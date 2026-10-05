<?php

namespace App\Enums;

/**
 * Kind of comment left on an artefact.
 */
enum CommentKind: string
{
    case Comment = 'comment';
    case SendBack = 'send_back';
    case ApprovalNote = 'approval_note';
    case BoardNote = 'board_note';
}
