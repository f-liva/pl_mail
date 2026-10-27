<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Domain\Enum\Mail\CategoryFiling;
use App\Domain\Enum\Mail\SenderVerdict;
use App\Entity\Mail\Message;
use App\Entity\Rule\MailRule;
use App\Jmap\Query\EmailFilterCompiler;
use App\Repository\Mail\InboxCategoryRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Rule\MailRuleRepository;
use App\Service\Mail\SenderAuthentication;
use Psr\Log\LoggerInterface;

/**
 * "Test my filters on this message": which of the reader's filters match it,
 * in the order they run, and what each would do — without doing any of it.
 *
 * Written for the question nobody could answer from the screen: why a tab
 * stayed empty although the filter "applied". A match is not a filing — File
 * in category also needs the receiving server to vouch for the sender — so
 * that action says which way RuleCategoryFiler would decide, through the same
 * code (check(), the side-effect-free half of file()). And the sender verdict
 * is given once, plainly, since it is the reason most often.
 *
 * It follows what MailRuleEngine does with arriving mail: enabled rules only,
 * in order, the ones scoped to another account left out, and nothing after a
 * matching rule with "Stop after this filter" — those are reported as stopped
 * rather than silently missing.
 */
final readonly class RuleMessageCheck
{
    public function __construct(
        private MailRuleRepository      $rules,
        private MessageRepository       $messages,
        private EmailFilterCompiler     $compiler,
        private RuleCategoryFiler       $categoryFiler,
        private InboxCategoryRepository $categories,
        private SenderAuthentication    $authentication,
        private LoggerInterface         $logger,
    ) {}

    /**
     * @return array{
     *     verdict: SenderVerdict,
     *     rules: list<array{
     *         rule: MailRule,
     *         state: 'matches'|'no_match'|'stopped'|'broken',
     *         stoppedBy: ?MailRule,
     *         actions: list<array{type: string, filing: ?CategoryFiling, category: ?string}>,
     *     }>,
     * }
     */
    public function check(Message $message): array
    {
        $user    = $message->account->usr;
        $results = [];
        $stopped = null;

        foreach (null === $user ? [] : $this->rules->findEnabledForUserOrdered($user) as $rule) {
            if (false === $rule->appliesTo($message->account)) {
                continue;
            }

            if (null !== $stopped) {
                $results[] = ['rule' => $rule, 'state' => 'stopped', 'stoppedBy' => $stopped, 'actions' => []];

                continue;
            }

            try {
                $matches = [] !== $this->messages->matchingIds(
                    [(int) $message->id],
                    $this->compiler->compile($rule->conditions),
                );
            } catch (\Throwable $e) {
                // The engine skips a rule whose conditions no longer compile,
                // with a warning; this says so instead of pretending it did
                // not match.
                $this->logger->warning('RuleMessageCheck: rule did not compile', [
                    'ruleId' => $rule->id,
                    'error'  => $e->getMessage(),
                ]);

                $results[] = ['rule' => $rule, 'state' => 'broken', 'stoppedBy' => null, 'actions' => []];

                continue;
            }

            if (false === $matches) {
                $results[] = ['rule' => $rule, 'state' => 'no_match', 'stoppedBy' => null, 'actions' => []];

                continue;
            }

            $results[] = [
                'rule'      => $rule,
                'state'     => 'matches',
                'stoppedBy' => null,
                'actions'   => $this->actions($rule, $message),
            ];

            if (true === $rule->stopProcessing) {
                $stopped = $rule;
            }
        }

        return [
            'verdict' => $this->authentication->verdict($message),
            'rules'   => $results,
        ];
    }

    /**
     * @return list<array{type: string, filing: ?CategoryFiling, category: ?string}>
     */
    private function actions(MailRule $rule, Message $message): array
    {
        $actions = [];

        foreach ($rule->actions as $action) {
            if (false === is_array($action) || false === is_string($action['type'] ?? null)) {
                continue;
            }

            $type = $action['type'];

            if (RuleActionExecutor::FILE_IN_CATEGORY !== $type) {
                $actions[] = ['type' => $type, 'filing' => null, 'category' => null];

                continue;
            }

            $category = is_int($action['categoryId'] ?? null) ? $this->categories->find($action['categoryId']) : null;

            $actions[] = [
                'type'     => $type,
                'filing'   => $this->categoryFiler->check($rule, $message, $action),
                'category' => null !== $category && $category->usr === $rule->usr ? $category->name : null,
            ];
        }

        return $actions;
    }
}
