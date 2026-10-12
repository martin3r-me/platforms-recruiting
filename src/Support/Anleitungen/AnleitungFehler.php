<?php

namespace Platform\Recruiting\Support\Anleitungen;

/** Interner Abbruch beim Lesen einer Anleitung — wird in AnleitungParser::parse() aufgefangen. */
final class AnleitungFehler extends \RuntimeException {}
