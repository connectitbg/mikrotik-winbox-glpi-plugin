# Winbox - GLPI 0.4.7 test report

Date: 2026-11-21

## Checks run

- Built `client/Winbox-GLPI-Setup.exe` with the .NET Framework C# compiler. The build script verified that the GLPI URL marker occurs exactly once.
- Ran the configured executable self-test against a local mock GLPI URL.
- Ran a local end-to-end SSH-handler smoke test against a mock GLPI HTTP endpoint and temporary fake PuTTY/KiTTY executables. Both flows exchanged a one-time code, received the test host, port, username, and password, started the matching fake process, and reported `started` to the mock endpoint.
- The fake clients verified that PuTTY received the test password through `-pwfile` and KiTTY received it through `-pass`, including a password containing spaces and a double quote. The password was not written to the test report or client launch log.
- Ran PHP syntax checks on the install page, plugin metadata, and SSH launch/redeem/report controllers. All passed.
- Parsed the PowerShell fallback launcher and checked the 0.4.7 marketplace XML metadata.

## Limits

The SSH smoke test used fake clients and did not connect to a physical MikroTik router. The access log records that a client process started; it does not confirm RouterOS authentication. Browser protocol confirmation and Chrome's external-application prompt must still be checked on the target Windows computer.
