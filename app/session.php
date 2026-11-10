<?php

declare(strict_types=1);

function openChatSession(): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_start(['cookie_secure' => $https]);
}
