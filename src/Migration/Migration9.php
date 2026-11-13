<?php

namespace App\Migration;

use PDO;

final readonly class Migration9 implements Migration
{
    public function migrate(PDO $pdo): void
    {
        $pdo->exec('create table library (game_id integer primary key, title text not null, slug text, hidden boolean not null default false, tags text not null default \'[]\')');
    }

    public function getVersion(): int
    {
        return 9;
    }
}
