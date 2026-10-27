**A filter that files into a tab now says how much it could not file.** **Apply to existing mail**
counted the messages a filter matched, so a run could read "Applied to 81 messages" and file none
of them, leaving an empty tab that never appears. A second line under the filter now counts the
messages left out because the receiving server did not verify their sender. Adds one column,
`mail_rule.run_unfiled`; the migration is reversible.
[#383](https://github.com/karatektus/pl_mail/issues/383), by @jo2904.
