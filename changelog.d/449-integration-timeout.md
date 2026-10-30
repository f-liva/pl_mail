**A file picker on an unreachable Paperless, Immich or Nextcloud now says so instead of returning an
error page.** A self-hosted service that accepts the connection and then stops answering used to
hold the request open until PHP's `max_execution_time` killed it, which took the worker thread with
it and never reached the message the picker had ready. Every integration request now gives up after
ten seconds of silence. Uploads and downloads still have no limit on how long a transfer may take.
No schema change. [#449](https://github.com/karatektus/pl_mail/issues/449).
