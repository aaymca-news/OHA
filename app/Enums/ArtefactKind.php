<?php

namespace App\Enums;

/**
 * The three artefacts every assessment produces, in gate order.
 */
enum ArtefactKind: string
{
    case Form = 'form';
    case Report = 'report';
    case Odp = 'odp';

    public function label(): string
    {
        return __('oha.artefact.'.$this->value);
    }
}
