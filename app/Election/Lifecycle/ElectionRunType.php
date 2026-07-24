<?php

namespace App\Election\Lifecycle;

enum ElectionRunType: string
{
    case Rehearsal = 'rehearsal';
    case Certification = 'certification';
    case ElectionDay = 'election-day';
    case Audit = 'audit';
    case AutomatedTest = 'automated-test';
}
