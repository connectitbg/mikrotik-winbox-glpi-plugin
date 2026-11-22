<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InstallClientController extends AbstractController
{
    #[Route('/InstallClient', name: 'winbox_install_client', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function __invoke(Request $request): Response
    {
        $files = [
            'Winbox-GLPI-Setup.exe' => GLPI_ROOT . '/plugins/winbox/client/Winbox-GLPI-Setup.exe',
            'INSTALL-WINBOX-GLPI.ps1' => GLPI_ROOT . '/plugins/winbox/client/INSTALL-WINBOX-GLPI.ps1',
            'WINBOX-GLPI.reg' => GLPI_ROOT . '/plugins/winbox/client/WINBOX.reg',
            'launch_winbox.ps1' => GLPI_ROOT . '/plugins/winbox/client/launch_winbox.ps1',
            'READ ME INSTALL.txt' => GLPI_ROOT . '/plugins/winbox/client/READ ME INSTALL.txt',
        ];
        $name = $request->query->getString('file');

        // Keep old download links working while distinguishing the current file from archived copies.
        if ($name === 'WINBOX.reg') {
            $name = 'WINBOX-GLPI.reg';
        }

        global $CFG_GLPI;
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $baseUrl = rtrim($request->getSchemeAndHttpHost() . $root, '/');

        if ($name !== '') {
            if (!isset($files[$name]) || !is_file($files[$name])) {
                return new Response('File not found', 404);
            }

            $content = file_get_contents($files[$name]);
            if ($content === false) {
                return new Response('File could not be read', 500);
            }

            if ($name === 'launch_winbox.ps1') {
                $content = str_replace('__WINBOX_GLPI_REDEEM_URL__', $baseUrl . '/plugins/winbox/Redeem', $content);
            } elseif ($name === 'INSTALL-WINBOX-GLPI.ps1') {
                $content = str_replace('__WINBOX_GLPI_BASE_URL__', $baseUrl, $content);
            } elseif ($name === 'Winbox-GLPI-Setup.exe') {
                $marker = mb_convert_encoding(str_repeat('~', 256), 'UTF-16LE', 'UTF-8');
                $serverUrl = mb_convert_encoding($baseUrl, 'UTF-16LE', 'UTF-8');
                if (strlen($serverUrl) > strlen($marker) || substr_count($content, $marker) !== 1) {
                    return new Response('The client installer template is invalid for this GLPI server.', 500);
                }
                $replacement = $serverUrl . str_repeat("\0", strlen($marker) - strlen($serverUrl));
                $content = str_replace($marker, $replacement, $content);
            }

            $type = in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['reg', 'exe'], true)
                ? 'application/octet-stream'
                : 'text/plain; charset=utf-8';
            $disposition = 'attachment; filename="' . addcslashes($name, '"\\')
                . '"; filename*=UTF-8\'\'' . rawurlencode($name);

            return new Response($content, 200, [
                'Content-Type' => $type,
                'Content-Disposition' => $disposition,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ]);
        }

        $installerUrl = $root . '/plugins/winbox/InstallClient?file='
            . rawurlencode('Winbox-GLPI-Setup.exe');
        $transportNoticeEn = '';
        $transportNoticeBg = '';
        if (!$request->isSecure()) {
            $transportNoticeEn = '<div class="notice"><b>HTTP security warning:</b> This GLPI server is using HTTP. The installer download and GLPI handoff are not encrypted in transit. Use test credentials only and enable HTTPS before production use.</div>';
            $transportNoticeBg = '<div class="notice"><b>Предупреждение за HTTP:</b> Този GLPI сървър използва HTTP. Изтеглянето на инсталатора и обменът с GLPI не са криптирани при пренос. Използвайте само тестови данни и включете HTTPS преди продукционна употреба.</div>';
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MikroTik Tools for GLPI - Windows client setup</title>
<style>
body{font:16px Arial;max-width:820px;margin:32px auto;padding:0 16px;line-height:1.55;color:#202733}
.notice{padding:12px 16px;background:#fff3cd;border-left:4px solid #e0a800;margin:18px 0}
.download{display:inline-block;padding:10px 16px;background:#0d6efd;color:white;text-decoration:none;border-radius:4px}
details{margin-top:24px} code{overflow-wrap:anywhere}
</style>
</head>
<body>
<section id="english" lang="en">
<h1>MikroTik Tools for GLPI</h1>
<p>Check for WinBox, PuTTY, and KiTTY, install missing clients, and register per-user Windows protocol handlers.</p>
<p>Run this setup again to repair its per-user protocol handlers or retry a failed download. Existing applications are not upgraded or replaced.</p>
__TRANSPORT_NOTICE_EN__
<h2>1. Install with one file</h2>
<ol>
<li>Download <code>Winbox-GLPI-Setup.exe</code> and double-click it on the Windows computer used to access GLPI.</li>
<li>The setup registers the WinBox, PuTTY, KiTTY, and SSH diagnostic handlers for the current Windows user. No separate downloads, PowerShell commands, <code>.reg</code> file, or administrator rights are required.</li>
<li>The installer detects existing programs and leaves them unchanged. It automatically downloads only missing or incompatible components from official HTTPS sources, verifies pinned SHA-256 checksums, and stores managed copies under <code>%LOCALAPPDATA%\Winbox-GLPI\clients</code>. Internet access to the relevant vendor hosts is needed only when a component must be added.</li>
<li>Wait for the completion message. It reports what was found, installed, or could not be downloaded. Run the same file again to repair handlers or retry a failed download.</li>
</ol>
<p><a class="download" href="__INSTALLER_URL__">Install WinBox, PuTTY &amp; KiTTY — Winbox-GLPI-Setup.exe</a></p>
<h2>What the installer sets up</h2>
<p>One file configures WinBox, PuTTY, KiTTY, and Plink for this Windows user. Current pinned package versions are WinBox 4.5, PuTTY/Plink 0.85, and KiTTY 0.76.1.13. Compatible existing applications are not upgraded or replaced. Managed copies are stored under <code>%LOCALAPPDATA%\Winbox-GLPI\clients</code>.</p>
<div class="notice"><b>KiTTY security note:</b> The KiTTY release used by this installer has a documented risk from crafted terminal sequences sent by a connected host. Prefer PuTTY for routers or SSH hosts you do not fully trust. KiTTY also receives the saved password as a command-line option, which other local processes may be able to inspect.</div>
<p class="notice"><b>Unsigned installer:</b> This installer is not digitally signed. Download it only from a GLPI server you trust. Windows or your browser may show a security prompt.</p><h2>2. Connect to a device</h2>
<ol>
<li>In GLPI, open the network equipment record and select the <b>Winbox</b> tab.</li>
<li>Click <b>Open in Winbox</b>, then click <b>Start Winbox</b> on the handoff page within 90 seconds.</li>
<li>For SSH, click <b>Open SSH in PuTTY</b> or <b>Open SSH in KiTTY</b>. The client opens with the saved device, SSH port, username, and password; users do not enter credentials at launch. If the client or password is missing, rerun setup or ask a GLPI administrator to configure the device credentials. If RouterOS rejects the saved password, the handler cannot change that router response.</li>
<li>To locate an SSH failure, click <b>Test SSH login</b>. The diagnostic uses Plink with the same saved GLPI credentials and performs one read-only RouterOS identity check. It reports whether authentication was accepted, rejected, blocked by host-key trust, or stopped by connectivity. It does not write a router log or save command output.</li>
<li>The browser may ask to open the external application. Confirm the prompt when shown. Browser security controls this confirmation; the installer does not suppress it.</li>
</ol>
<h2>If an application does not start</h2>
<ul>
<li>Check <code>%LOCALAPPDATA%\Winbox-GLPI\winbox-glpi-launch.log</code>. If the setup could not download a client, allow access to the official download hosts, then run the setup file again. The one-time code is not redeemed until a local client is found.</li>
<li>If Windows does not open the application, run <code>Winbox-GLPI-Setup.exe</code> again so it repairs the per-user protocol handlers and retries any missing client downloads.</li>
<li>If the log contains an error, send it to the GLPI administrator. It does not contain the password or one-time launch code.</li>
<li>Check the setup completion message for each client. Run <code>Winbox-GLPI-Setup.exe</code> again to retry a missing download or repair the URL handlers.</li>
</ul>
<h2>Access log</h2>
<p>In GLPI, open <b>Administration → Logs → Log files → mikrotik_access.log</b>. Entries include the GLPI user, time, device, target, and whether the Winbox process started. Router authentication itself is not verified.</p>
<p class="notice"><b>Temporary SSH test diagnostics:</b> After clicking <b>Test SSH login</b>, check <code>%LOCALAPPDATA%\Winbox-GLPI\winbox-glpi-ssh-test.log</code> on the Windows client. It records the result, SSH server fingerprint, safe MAC algorithm hints, and authentication stages. It never records the password, launch code, raw router response, or router logs. The temporary diagnostic button and logging will be removed after this test pass.</p>
</section>
<hr>
<details>
<summary>Български инструкции</summary>
<section id="bulgarian" lang="bg">
<h1>MikroTik Tools for GLPI</h1>
<p>Инсталаторът настройва WinBox, PuTTY, KiTTY и Plink за текущия Windows потребител.</p>
<p>Стартирайте го на Windows компютъра, от който използвате GLPI. Не са нужни отделни изтегляния, PowerShell команди, <code>.reg</code> файл или администраторски права.</p>
__TRANSPORT_NOTICE_BG__
<h2>1. Инсталиране с един файл</h2>
<ol>
<li>Изтеглете <code>Winbox-GLPI-Setup.exe</code> и го стартирайте с двоен клик на Windows компютъра, от който използвате GLPI.</li>
<li>Инсталаторът регистрира обработчиците за WinBox, PuTTY, KiTTY и SSH диагностиката за текущия Windows потребител.</li>
<li>Той открива наличните програми и ги оставя без промяна. Автоматично изтегля само липсващи или несъвместими компоненти от официални HTTPS източници, проверява фиксираните SHA-256 суми и записва управляваните копия в <code>%LOCALAPPDATA%\Winbox-GLPI\clients</code>. Интернет достъп до съответния издател е нужен само когато трябва да се добави компонент.</li>
<li>Изчакайте финалното съобщение — то показва какво е открито, инсталирано или не е могло да бъде изтеглено. Стартирайте същия файл отново, за да поправите обработчиците или да повторите неуспешно изтегляне.</li>
</ol>
<p><a class="download" href="__INSTALLER_URL__">Инсталирай WinBox, PuTTY и KiTTY — Winbox-GLPI-Setup.exe</a></p>
<h2>Какво настройва инсталаторът</h2>
<p>Един файл настройва WinBox, PuTTY, KiTTY и Plink. Фиксираните версии са WinBox 4.5, PuTTY/Plink 0.85 и KiTTY 0.76.1.13. Съвместимите налични програми не се обновяват или заменят. Управляваните копия се записват в <code>%LOCALAPPDATA%\Winbox-GLPI\clients</code>.</p>
<div class="notice"><b>Бележка за сигурността на KiTTY:</b> Версията на KiTTY в този инсталатор има документиран риск от специално оформени терминални последователности, изпратени от свързан хост. Предпочитайте PuTTY за рутери или SSH сървъри, на които нямате пълно доверие. KiTTY получава запазената парола като аргумент на командния ред, който може да бъде видим за други локални процеси.</div>
<p class="notice"><b>Инсталаторът не е цифрово подписан:</b> Изтегляйте го само от GLPI сървър, на който имате доверие. Windows или браузърът може да покаже предупреждение за сигурност.</p><h2>2. Свързване към устройство</h2>
<ol>
<li>В GLPI отворете мрежовото устройство и раздел <b>Winbox</b>.</li>
<li>Натиснете <b>Open in Winbox</b>, след това <b>Стартирай Winbox</b> на междинната страница до 90 секунди.</li>
<li>За SSH използвайте <b>Open SSH in PuTTY</b> или <b>Open SSH in KiTTY</b>. Клиентът използва запазените адрес, SSH порт, потребител и парола; потребителят не въвежда данни при стартиране. Ако клиентът или запазената парола липсват, обработчикът показва инструкция за настройка, вместо да отваря интерактивен вход. Ако RouterOS отхвърли запазената парола, GLPI администратор трябва да обнови данните за устройството или SSH акаунта.</li>
<li>За локализиране на SSH проблем натиснете <b>Test SSH login</b>. Plink използва същите запазени в GLPI данни и изпълнява една команда само за прочит на RouterOS identity. Резултатът показва дали входът е приет или отказан, дали липсва доверен host key, или връзката не достига до SSH. Не се записва лог или изход от команда в рутера.</li>
<li>Браузърът може да поиска потвърждение за външното приложение. Потвърдете го, когато се появи. Инсталаторът не изключва тази защита на браузъра.</li>
</ol>
<h2>Ако програма не се стартира</h2>
<ul>
<li>Проверете <code>%LOCALAPPDATA%\Winbox-GLPI\winbox-glpi-launch.log</code>. Ако инсталаторът не е могъл да изтегли програма, разрешете достъп до официалните адреси за изтегляне и стартирайте файла отново. Еднократният код не се използва, докато няма налична локална програма.</li>
<li>Ако Windows не отваря програмата, стартирайте <code>Winbox-GLPI-Setup.exe</code> отново, за да поправите обработчиците и да опитате повторно изтеглянето на липсващите SSH програми.</li>
<li>Ако логът съдържа грешка, изпратете го на GLPI администратора. В него не се записват паролата или еднократният код.</li>
<li>Проверете финалното съобщение на инсталатора за състоянието на всяка програма. Стартирайте <code>Winbox-GLPI-Setup.exe</code> отново, за да повторите изтегляне или да поправите обработчиците.</li>
</ul>
<h2>Лог за достъп</h2>
<p>В GLPI отворете <b>Администрация → Логове → Файлове с логове → mikrotik_access.log</b>. Записите съдържат потребителя в GLPI, часа, устройството, адреса и дали процесът Winbox е стартирал. Самата автентикация в рутера не се проверява.</p>
<p class="notice"><b>Временна диагностика за SSH:</b> След натискане на <b>Test SSH login</b> проверете <code>%LOCALAPPDATA%\Winbox-GLPI\winbox-glpi-ssh-test.log</code> на Windows клиента. В него се записват резултатът, fingerprint-ът на SSH сървъра, безопасни данни за MAC алгоритъма и етапите на автентикация. Не се записват паролата, еднократният код, суровият отговор от рутера или router logs. Временните бутон и логване ще бъдат премахнати след тестовете.</p>
</section>
</details>

</body>
</html>
HTML;
        $html = strtr($html, [
            '__INSTALLER_URL__' => htmlspecialchars($installerUrl, ENT_QUOTES, 'UTF-8'),
            '__TRANSPORT_NOTICE_EN__' => $transportNoticeEn,
            '__TRANSPORT_NOTICE_BG__' => $transportNoticeBg,
        ]);

        return new Response($html, 200, ['Cache-Control' => 'no-store']);
    }
}
