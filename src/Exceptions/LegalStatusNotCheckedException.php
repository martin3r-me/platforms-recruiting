<?php

namespace Platform\Recruiting\Exceptions;

/**
 * Wird von HrDeskRoutingService::approveCase geworfen, wenn ein
 * non_eu_citizen-Fall freigegeben werden soll, obwohl der Rechtsstatus noch
 * nicht geprüft ist.
 */
class LegalStatusNotCheckedException extends \DomainException
{
    public function __construct(public readonly int $applicantId)
    {
        parent::__construct('Rechtsstatus noch nicht geprüft — Freigabe nicht möglich.');
    }
}
