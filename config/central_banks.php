<?php

/*
|--------------------------------------------------------------------------
| Central Bank Calendar (docs/specs/macro-series-fred.md, Domain Rule 7)
|--------------------------------------------------------------------------
|
| Monetary policy meeting dates copied by hand from the official calendars,
| ascending. `start` is the first day of the meeting and `decision` the day
| the decision is announced (day 2). App\Services\Macro\CentralBankCalendar
| reads them; no date is ever guessed. Refresh this file yearly:
| CentralBankCalendarTest fails when either bank has no decision date at
| least 60 days ahead. FOMC dates of the following year are tentative until
| confirmed at the previous meeting.
|
*/

return [
    'fed' => [
        'name' => 'Federal Open Market Committee',
        'source_url' => 'https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm',
        'checked_at' => '2026-10-22',
        'meetings' => [
            ['start' => '2026-01-27', 'decision' => '2026-01-28'],
            ['start' => '2026-03-17', 'decision' => '2026-03-18'],
            ['start' => '2026-04-28', 'decision' => '2026-04-29'],
            ['start' => '2026-06-16', 'decision' => '2026-06-17'],
            ['start' => '2026-07-28', 'decision' => '2026-07-29'],
            ['start' => '2026-09-15', 'decision' => '2026-09-16'],
            ['start' => '2026-10-27', 'decision' => '2026-10-28'],
            ['start' => '2026-12-08', 'decision' => '2026-12-09'],
            // 2027: tentative until confirmed at the previous meeting.
            ['start' => '2027-01-26', 'decision' => '2027-01-27'],
            ['start' => '2027-03-16', 'decision' => '2027-03-17'],
            ['start' => '2027-04-27', 'decision' => '2027-04-28'],
            ['start' => '2027-06-08', 'decision' => '2027-06-09'],
            ['start' => '2027-07-27', 'decision' => '2027-07-28'],
            ['start' => '2027-09-14', 'decision' => '2027-09-15'],
            ['start' => '2027-10-26', 'decision' => '2027-10-27'],
            ['start' => '2027-12-07', 'decision' => '2027-12-08'],
        ],
    ],

    'ecb' => [
        'name' => 'ECB Governing Council (monetary policy meetings)',
        'source_url' => 'https://www.ecb.europa.eu/press/calendars/mgcgc/html/index.en.html',
        'checked_at' => '2026-10-22',
        'meetings' => [
            ['start' => '2026-02-04', 'decision' => '2026-02-05'],
            ['start' => '2026-03-18', 'decision' => '2026-03-19'],
            ['start' => '2026-04-29', 'decision' => '2026-04-30'],
            ['start' => '2026-06-10', 'decision' => '2026-06-11'],
            ['start' => '2026-07-22', 'decision' => '2026-07-23'],
            ['start' => '2026-09-09', 'decision' => '2026-09-10'],
            ['start' => '2026-10-28', 'decision' => '2026-10-29'],
            ['start' => '2026-12-16', 'decision' => '2026-12-17'],
            ['start' => '2027-02-03', 'decision' => '2027-02-04'],
            ['start' => '2027-03-17', 'decision' => '2027-03-18'],
            ['start' => '2027-04-28', 'decision' => '2027-04-29'],
            ['start' => '2027-06-09', 'decision' => '2027-06-10'],
            ['start' => '2027-07-21', 'decision' => '2027-07-22'],
            ['start' => '2027-09-08', 'decision' => '2027-09-09'],
            ['start' => '2027-10-27', 'decision' => '2027-10-28'],
            ['start' => '2027-12-15', 'decision' => '2027-12-16'],
        ],
    ],
];
