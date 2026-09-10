<?php

namespace App\Services\Conversations\Exceptions;

use RuntimeException;

final class ConversationHasPendingAnswer extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Conversation has a pending assistant answer.',
        );
    }
}
