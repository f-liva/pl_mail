<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * What became of a filter's "File in category" action on one message.
 *
 * Two ways of not filing, kept apart because the reader fixes them in
 * different places: a tab that is gone is a filter to edit, an unverified
 * sender is a property of the mail and its receiving server. "Apply to
 * existing mail" counts the second (MailRule::$runUnfiled), because without
 * that count a run reads "Applied to 81 messages" and files none of them.
 *
 * See App\Service\Rule\RuleCategoryFiler.
 */
enum CategoryFiling
{
    /** The message is under the reader's category now. */
    case Filed;

    /** The receiving server did not vouch for the sender, so it stays where it was. */
    case SenderNotVerified;

    /** No category to file under: none named, deleted, or not the rule owner's. */
    case NoCategory;
}
