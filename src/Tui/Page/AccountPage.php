<?php

namespace App\Tui\Page;

use App\Service\WebAuthentication;
use App\Tui\App;
use App\Tui\Canvas;
use App\Tui\Theme;
use App\Tui\Widget\Button;
use App\Tui\Widget\TextInput;
use Throwable;

final class AccountPage extends Page
{
    private Button $open;

    private TextInput $code;

    private Button $login;

    private Button $logout;

    private Button $check;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->open = $this->form->add(new Button('Open GOG Login Page', $this->openBrowser(...)));
        $this->code = $this->form->add(new TextInput(40, onSubmit: fn () => $this->doLogin(), placeholder: 'paste the address here'));
        $this->login = $this->form->add(new Button('Log In', $this->doLogin(...)));
        $this->check = $this->form->add(new Button('Check Login', $this->checkLogin(...)));
        $this->logout = $this->form->add(new Button('Log Out', $this->doLogout(...)));
    }

    public function getMenuLabel(): string
    {
        return 'Account / Login';
    }

    public function render(Canvas $canvas, int $x, int $y, int $width, int $height, bool $focused): void
    {
        $this->heading($canvas, $x, $y, $width, 'GOG Account');
        $row = $y + 2;

        $loggedIn = $this->app->isLoggedIn();
        $canvas->text($x, $row, 'Status:', Theme::LABEL);
        $canvas->text($x + 9, $row, $loggedIn ? '( logged in )' : '( not logged in )', $loggedIn ? Theme::GOOD : Theme::BAD);
        if ($loggedIn && $this->app->getUsername()) {
            $canvas->text($x + 26, $row, $this->app->getUsername(), Theme::VALUE);
        }
        $row += 2;

        $canvas->text($x, $row, 'Log in', Theme::LIST_HEADER);
        ++$row;
        $steps = [
            '1. Open the GOG login page in your browser (button below) and log in.',
            '2. After logging in you end up on a blank page. Copy its address from',
            '   the browser address bar (it contains "on_login_success?...code=").',
            '3. Paste the address (or just the code) into the field and press Log In.',
        ];
        foreach ($steps as $step) {
            $canvas->text($x + 2, $row++, $step, Theme::TEXT, $width - 2);
        }
        ++$row;

        $this->open->x = $x + 2;
        $this->open->y = $row;
        $row += 2;

        $canvas->text($x + 2, $row, 'Address or code:', Theme::LABEL);
        $this->code->x = $x + 19;
        $this->code->y = $row;
        $this->code->width = max(20, $width - 21);
        $row += 2;

        $this->login->x = $x + 2;
        $this->login->y = $row;
        $this->check->x = $this->login->x + $this->login->getWidth() + 2;
        $this->check->y = $row;
        $this->logout->x = $this->check->x + $this->check->getWidth() + 2;
        $this->logout->y = $row;
        $this->check->enabled = $loggedIn;
        $this->logout->enabled = $loggedIn;
        $row += 2;

        $canvas->text($x, $row, 'If the browser does not open, visit this address manually:', Theme::DIM, $width);
        $url = WebAuthentication::AUTH_URL;
        foreach (str_split($url, max(10, $width)) as $index => $part) {
            $canvas->text($x, $row + 1 + $index, $part, Theme::INFO);
        }

        $this->form->render($canvas, $focused);
    }

    private function openBrowser(): void
    {
        $url = WebAuthentication::AUTH_URL;
        // an array command bypasses the shell, so the & and % characters in the url are passed as is
        $command = match (PHP_OS_FAMILY) {
            'Windows' => ['rundll32', 'url.dll,FileProtocolHandler', $url],
            'Darwin' => ['open', $url],
            default => ['xdg-open', $url],
        };
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        if ($process === false) {
            $this->app->showMessage('Browser', 'Could not open the browser, please visit the address shown below manually.');

            return;
        }
        proc_close($process);
        $this->app->log('Opened the GOG login page in the browser.');
        $this->form->focus($this->code);
    }

    private function doLogin(): void
    {
        $value = trim($this->code->value);
        if ($value === '') {
            $this->app->showMessage('Log In', 'Paste the address of the page you were redirected to after logging in first.');
            $this->form->focus($this->code);

            return;
        }

        $code = $value;
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            parse_str((string) parse_url($value, PHP_URL_QUERY), $query);
            if (!isset($query['code']) || !is_string($query['code'])) {
                $this->app->showError('Log In', 'The address does not contain a login code. Make sure you copied the address of the blank page you were redirected to.');

                return;
            }
            $code = $query['code'];
        }

        $this->app->busy('Logging in...', function () use ($code) {
            $this->app->authentication->codeLogin($code);
            $this->code->setValue('');
            $this->app->reloadData();
            $this->app->log('Logged in successfully.');
            $this->loadUsername();
        });

        if ($this->app->isLoggedIn()) {
            $library = $this->app->getLibrary();
            if (!count($library)) {
                $this->app->confirm('Logged In', [
                    'You are now logged in.',
                    '',
                    'Load your game library from GOG now?',
                ], function () {
                    $this->app->refreshLibrary();
                    $this->app->selectPage(LibraryPage::class, focusContent: true);
                }, 'Load Library', 'Later');
            } else {
                $this->app->showMessage('Logged In', 'You are now logged in.');
            }
        }
    }

    private function checkLogin(): void
    {
        $this->app->busy('Checking login...', function () {
            $this->loadUsername();
            $this->app->showMessage('Login OK', 'Logged in as ' . ($this->app->getUsername() ?? 'unknown user') . '.');
        });
    }

    private function loadUsername(): void
    {
        try {
            $this->app->setUsername($this->app->authentication->getUserInfo()->username);
        } catch (Throwable $e) {
            $this->app->setUsername(null);
            throw $e;
        }
    }

    private function doLogout(): void
    {
        $this->app->confirm('Log Out', 'Remove the stored GOG login from this computer?', function () {
            $this->app->persistence->saveAuthorization(null);
            $this->app->setUsername(null);
            $this->app->reloadData();
            $this->app->log('Logged out.');
        }, 'Log Out', 'Cancel');
    }
}
