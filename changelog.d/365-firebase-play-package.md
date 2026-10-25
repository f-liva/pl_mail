**Push to the Android app from Google Play.** The app is on Google Play as `de.pluetzner.plmail`,
and that is now the Android client the server picks out of your `google-services.json`, ahead of
`de.plmail.google` (the GitHub builds up to v0.4.0) and then the first one listed. If you use
Firebase push, add an Android app with the package name `de.pluetzner.plmail` to your Firebase
project and paste the new `google-services.json` under **Admin → Push**. No schema change.
[#365](https://github.com/karatektus/pl_mail/issues/365).
