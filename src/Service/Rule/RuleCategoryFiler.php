<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Domain\Enum\Mail\CategoryFiling;
use App\Domain\Enum\Mail\SenderVerdict;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\Message;
use App\Entity\Rule\MailRule;
use App\Repository\Mail\InboxCategoryRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Mail\SenderAuthentication;
use Psr\Log\LoggerInterface;

/**
 * Files a message under one of the reader's own categories, when a filter's
 * "File in category" action says to — and refuses when filing it would put
 * something where it must not be.
 *
 * THE REFUSALS ARE THE POINT
 * ──────────────────────────
 * A filter names a sender, and a sender is a line anybody can write. A reader
 * who builds a "Shipping" tab from "sender's domain is mondialrelay.fr" has
 * made somewhere that parcel-delivery phishing, sent from a forged
 * mondialrelay.fr, would sit beside the real notices looking exactly as
 * legitimate as they do. The only thing that tells the two apart is the
 * receiving server's authentication verdict, so this files a message only when
 * SenderAuthentication says Pass. Not Fail, and — a decision, not an accident —
 * not Unknown: an account whose receiving server plMail cannot identify files
 * nothing, because "we could not tell" is exactly what a forgery relies on. The
 * cost is that such an account cannot use the feature; docs/features/filters.md
 * says so.
 *
 * WHAT THIS DOES NOT COVER, because its blind spots will be assumed covered
 * otherwise:
 *  - A Pass that a believed server gave wrongly — see SenderAuthentication for
 *    whose word counts.
 *  - The filter's own conditions. Authentication proves a message came from the
 *    domain it claims, not that the domain is the one the reader meant, so a
 *    filter that does not pin the sender ("subject contains parcel") files a
 *    lookalike domain that authenticates for itself.
 *  - The conversation. This gates the MESSAGE being filed, and a tab holds
 *    conversations: a forged message that replies into a genuine conversation
 *    (same sender address, a "Re:" subject) can join it, and a conversation the
 *    reader pinned into a tab keeps it whatever joins it. Unpinned, such a
 *    message is the newest and so takes the conversation OUT of the tab, unless
 *    a genuine message lands after it in the same provider conversation.
 *
 * What a refusal does is nothing: the message is left to the ordinary rules,
 * which is where it would have been had no filter matched. Filing only ever
 * chooses a tab — it never touches labels, the Spam folder, read state or the
 * authentication warning the message view shows.
 *
 * Gmail's own categories do not stand in the way. They outrank plMail's own
 * sorting unless the reader says otherwise (CategorySorting::$overrideProvider),
 * because both are guesses and Google's was there first. A filter is not a
 * guess: the reader wrote it, naming the mail it is for, and it files Gmail
 * mail like any other.
 *
 * Two filters matching one message: the one that runs last decides, because the
 * column is single-valued and this overwrites. "Stop after this filter" is the
 * existing way to make an earlier match final.
 */
final readonly class RuleCategoryFiler
{
    public function __construct(
        private InboxCategoryRepository $categories,
        private SenderAuthentication    $authentication,
        private MessageThreader         $threader,
        private LoggerInterface         $logger,
    ) {}

    /**
     * @param array<string,mixed> $action the stored {type, categoryId} entry
     */
    public function file(MailRule $rule, Message $message, array $action): CategoryFiling
    {
        [$outcome, $category] = $this->decide($rule, $message, $action, log: true);

        if (CategoryFiling::Filed !== $outcome || null === $category) {
            return $outcome;
        }

        $message->ruleCategory = $category;

        $thread = $message->thread;

        if (null !== $thread) {
            // The same most-recent-wins, pin and hold rules the built-in
            // category follows, rather than a second copy of them here.
            $this->threader->refreshCategory($message, $thread);
        }

        return CategoryFiling::Filed;
    }

    /**
     * What file() would answer, without filing anything — for "Test my
     * filters on this message", which has to say why a tab stayed empty
     * using the same reasons the filing itself uses.
     *
     * @param array<string,mixed> $action
     */
    public function check(MailRule $rule, Message $message, array $action): CategoryFiling
    {
        return $this->decide($rule, $message, $action, log: false)[0];
    }

    /**
     * @param array<string,mixed> $action
     *
     * @return array{0: CategoryFiling, 1: ?InboxCategory}
     */
    private function decide(MailRule $rule, Message $message, array $action, bool $log): array
    {
        $categoryId = $action['categoryId'] ?? null;

        if (false === is_int($categoryId)) {
            return [CategoryFiling::NoCategory, null];
        }

        $category = $this->categories->find($categoryId);

        // Re-checked at run time, not trusted from the stored action: whatever
        // is in the json, a rule must never reach a category its owner does not
        // own, and a deleted one is simply nothing to file under. The same
        // discipline as labels and integrations in RuleActionExecutor.
        if (null === $category || null === $rule->usr || $category->usr !== $rule->usr) {
            if (true === $log) {
                $this->logger->warning('RuleCategoryFiler: category not found for rule owner', [
                    'ruleId'     => $rule->id,
                    'categoryId' => $categoryId,
                ]);
            }

            return [CategoryFiling::NoCategory, null];
        }

        if (SenderVerdict::Pass !== $this->authentication->verdict($message)) {
            return [CategoryFiling::SenderNotVerified, $category];
        }

        return [CategoryFiling::Filed, $category];
    }
}
