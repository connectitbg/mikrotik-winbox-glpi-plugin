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
        $manualFiles = ['WINBOX-GLPI.reg', 'launch_winbox.ps1', 'READ ME INSTALL.txt'];
        $manualLinks = '';

        foreach ($manualFiles as $file) {
            $url = $root . '/plugins/winbox/InstallClient?file=' . rawurlencode($file);
            $manualLinks .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '</a></li>';
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Winbox - GLPI — Client setup</title>
<style>
body{font:16px Arial;max-width:820px;margin:32px auto;padding:0 16px;line-height:1.55;color:#202733}
.notice{padding:12px 16px;background:#fff3cd;border-left:4px solid #e0a800;margin:18px 0}
.download{display:inline-block;padding:10px 16px;background:#0d6efd;color:white;text-decoration:none;border-radius:4px}
details{margin-top:24px} code{overflow-wrap:anywhere}
</style>
</head>
<body>
<section id="english" lang="en">
<h1>Winbox - GLPI</h1>
<p>One-time setup of the Winbox, PuTTY, and KiTTY launch handlers for the signed-in Windows user.</p>
<p>If you installed an earlier version, run this installer again to register the new SSH handlers.</p>
<div class="notice"><b>Test environment:</b> GLPI is currently available over HTTP. Use test credentials only. Run the installer on the Windows client, not on the GLPI server.</div>
<h2>1. Install the GLPI handlers — one file</h2>
<ol>
<li>Install the applications you plan to use. Winbox is required for Winbox access; PuTTY or KiTTY is required for its matching SSH button.</li>
<li>Download <code>Winbox-GLPI-Setup.exe</code> and double-click it.</li>
<li>Wait for the success message. It shows whether PuTTY and KiTTY were detected and registers <code>mikrotik://</code>, <code>mikrotik-putty://</code>, and <code>mikrotik-kitty://</code> for the signed-in Windows user. No PowerShell commands, separate <code>.reg</code> file, or administrator rights are required.</li>
</ol>
<p><a class="download" href="__INSTALLER_URL__">Download Winbox-GLPI-Setup.exe</a></p>
<h2>2. Install the SSH client you want to use</h2>
<p>The setup EXE installs the GLPI handlers. PuTTY and KiTTY are separate third-party applications; install only the client you plan to use.</p>
<ul>
<li><a target="_blank" rel="noopener noreferrer" href="https://www.chiark.greenend.org.uk/~sgtatham/putty/latest.html">Download PuTTY from its official project site</a>.</li>
<li><a target="_blank" rel="noopener noreferrer" href="https://www.9bis.com/kitty/">Download KiTTY from its official project site</a>.</li>
</ul>
<p><small>The test installer is unsigned. Run it only if it was downloaded from this GLPI page.</small></p>
<h2>3. Connect to a device</h2>
<ol>
<li>In GLPI, open the network equipment record and select the <b>Winbox</b> tab.</li>
<li>Click <b>Open in Winbox</b>, then click <b>Start Winbox</b> on the handoff page within 90 seconds.</li>
<li>For SSH, click <b>Open SSH in PuTTY</b> or <b>Open SSH in KiTTY</b>. The client opens with the saved device, SSH port, username, and password. PuTTY 0.83 or newer is required for automatic password entry; older PuTTY versions will prompt.</li>
<li>The first time, confirm Chrome's prompt to open the external application.</li>
</ol>
<h2>If an application does not start</h2>
<ul>
<li>Check <code>%LOCALAPPDATA%\Winbox-GLPI\winbox-glpi-launch.log</code>. If it says PuTTY.exe or KiTTY.exe was not found, install that client and retry the same handoff button. The launch code is not redeemed until a local client is found.</li>
<li>If Windows does not open the application, run <code>Winbox-GLPI-Setup.exe</code> again so it repairs the per-user protocol handlers.</li>
<li>If the log contains an error, send it to the GLPI administrator. It does not contain the password or one-time launch code.</li>
<li>Make sure Winbox.exe is installed for Winbox, or PuTTY/KiTTY is installed for SSH.</li>
</ul>
<h2>Access log</h2>
<p>In GLPI, open <b>Administration → Logs → Log files → mikrotik_access.log</b>. Entries include the GLPI user, time, device, target, and whether the Winbox process started. Router authentication itself is not verified.</p>
</section>
<hr>
<details>
<summary>Български инструкции</summary>
<section id="bulgarian" lang="bg">
<h1>Winbox - GLPI</h1>
<p>Еднократна настройка на обработчиците за стартиране на Winbox, PuTTY и KiTTY за текущия Windows потребител.</p>
<p>Ако сте инсталирали по-стара версия, стартирайте инсталатора отново, за да регистрирате новите SSH обработчици.</p>
<div class="notice"><b>Тестова среда:</b> GLPI е достъпен по HTTP. Използвайте само тестови данни. Стартирайте инсталатора на клиентския Windows компютър, не на GLPI сървъра.</div>
<h2>1. Инсталирайте GLPI обработчиците — един файл</h2>
<ol>
<li>Инсталирайте програмите, които ще използвате. Winbox е нужен за Winbox достъп; PuTTY или KiTTY е нужен за съответния SSH бутон.</li>
<li>Изтеглете <code>Winbox-GLPI-Setup.exe</code> и го стартирайте с двоен клик.</li>
<li>Изчакайте потвърждението. То показва дали PuTTY и KiTTY са открити и регистрира <code>mikrotik://</code>, <code>mikrotik-putty://</code> и <code>mikrotik-kitty://</code> за текущия Windows профил. Не са нужни PowerShell команди, отделен <code>.reg</code> файл или администраторски права.</li>
</ol>
<p><a class="download" href="__INSTALLER_URL__">Изтегли Winbox-GLPI-Setup.exe</a></p>
<h2>2. Инсталирайте желания SSH клиент</h2>
<p>EXE файлът настройва обработчиците за GLPI. PuTTY и KiTTY са отделни програми; инсталирайте само тази, която ще използвате.</p>
<ul>
<li><a target="_blank" rel="noopener noreferrer" href="https://www.chiark.greenend.org.uk/~sgtatham/putty/latest.html">Изтегляне на PuTTY от официалния сайт</a>.</li>
<li><a target="_blank" rel="noopener noreferrer" href="https://www.9bis.com/kitty/">Изтегляне на KiTTY от официалния сайт</a>.</li>
</ul>
<p><small>Тестовият инсталатор не е цифрово подписан. Стартирайте го само ако е изтеглен от тази GLPI страница.</small></p>
<h2>3. Свързване към устройство</h2>
<ol>
<li>В GLPI отворете мрежовото устройство и раздел <b>Winbox</b>.</li>
<li>Натиснете <b>Open in Winbox</b>, след това <b>Стартирай Winbox</b> на междинната страница до 90 секунди.</li>
<li>За SSH използвайте <b>Open SSH in PuTTY</b> или <b>Open SSH in KiTTY</b>. Клиентът използва запазените адрес, SSH порт, потребител и парола. За автоматично попълване с PuTTY е нужна версия 0.83 или по-нова; по-старите версии ще поискат паролата.</li>
<li>При първото стартиране потвърдете в Chrome отварянето на външното приложение.</li>
</ol>
<h2>Ако програма не се стартира</h2>
<ul>
<li>Проверете <code>%LOCALAPPDATA%\Winbox-GLPI\winbox-glpi-launch.log</code>. Ако в него пише, че PuTTY.exe или KiTTY.exe не е открит, инсталирайте програмата и опитайте отново със същия бутон на междинната страница. Еднократният код не се използва, докато клиентът не бъде открит.</li>
<li>Ако Windows не отваря програмата, стартирайте <code>Winbox-GLPI-Setup.exe</code> отново, за да поправите обработчиците за текущия Windows профил.</li>
<li>Ако логът съдържа грешка, изпратете го на GLPI администратора. В него не се записват паролата или еднократният код.</li>
<li>Проверете, че Winbox.exe е инсталиран за Winbox или PuTTY/KiTTY за SSH.</li>
</ul>
<h2>Лог за достъп</h2>
<p>В GLPI отворете <b>Администрация → Логове → Файлове с логове → mikrotik_access.log</b>. Записите съдържат потребителя в GLPI, часа, устройството, адреса и дали процесът Winbox е стартирал. Самата автентикация в рутера не се проверява.</p>
</section>
</details>
<details>
<summary>Ръчна настройка / Manual fallback</summary>
<p>Използвайте тези файлове само ако автоматичният инсталатор не може да бъде стартиран. / Use these files only if the automatic installer cannot be run.</p>
<ul>
__MANUAL_LINKS__
</ul>
<p>Не използвайте стария номериран <code>2. WINBOX.reg</code>. / Do not use the old numbered <code>2. WINBOX.reg</code>.</p>
</details>
</body>
</html>
HTML;
        $html = strtr($html, [
            '__INSTALLER_URL__' => htmlspecialchars($installerUrl, ENT_QUOTES, 'UTF-8'),
            '__MANUAL_LINKS__' => $manualLinks,
        ]);

        return new Response($html, 200, ['Cache-Control' => 'no-store']);
    }
}
