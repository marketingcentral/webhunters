<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class LobbyController extends Controller
{
    private const MAX_CHAT = 12;

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('lobby');
        $data['news'] = data_mock('noticias')['items'] ?? [];

        // Messages typed in this session are appended to the mock transcript.
        $extra = $_SESSION['lobby_chat'] ?? [];
        if (is_array($extra) && $extra !== []) {
            $data['chat'] = array_slice(array_merge($data['chat'] ?? [], $extra), -self::MAX_CHAT);
        }

        $page = $this->page('lobby', 'Lobby', [
            'bg'    => 'bg/lobby.jpg',
            'three' => ['atmosphere' => true, 'hero' => true],
        ]);
        return $this->render('lobby', $page, $data);
    }

    /** POST /lobby/chat: CSRF, validation, flash, redirect back to the lobby. */
    public function chat(Request $request, array $params = []): Response
    {
        $this->csrf($request);
        $message = preg_replace('/\s+/u', ' ', (string)$request->input('message', '')) ?? '';
        $message = trim(mb_substr($message, 0, 200));

        if ($message === '') {
            flash('error', 'Digite uma mensagem antes de enviar.');
            return $this->redirect('lobby');
        }

        $chat   = is_array($_SESSION['lobby_chat'] ?? null) ? $_SESSION['lobby_chat'] : [];
        $chat[] = [
            'author' => $this->player()['name'],
            'text'   => $message,
            'time'   => date('H:i'),
            'self'   => true,
        ];
        $_SESSION['lobby_chat'] = array_slice($chat, -self::MAX_CHAT);

        flash('ok', 'Mensagem enviada.');
        return $this->redirect('lobby');
    }
}
