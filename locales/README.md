# Plugin translations

Plugin UI strings use the `winbox` gettext domain. Edit `bg_BG.po` for Bulgarian translations and compile it to `bg_BG.mo` before packaging:

```sh
msgfmt locales/bg_BG.po -o locales/bg_BG.mo
```

The standalone client-installation page intentionally presents complete English and Bulgarian instruction sections together, independent of the current GLPI language.
