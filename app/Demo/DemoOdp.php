<?php

namespace App\Demo;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * An INVENTED ODP for the demo site: AAYMCA's ODP template filled with plausible tasks,
 * deadlines and owners from a bank of typical tasks per strategic priority. Never a real
 * movement's plan. The platform reads its tasks as it reads any ODP.
 */
final class DemoOdp
{
    /** The template's rows for each priority's three tasks (1.1–9.3), in order. */
    private const ROWS = [
        'financial' => [18, 19, 20], 'governance' => [22, 23, 24], 'constitution' => [26, 27, 28],
        'me' => [30, 31, 32], 'strategy' => [34, 35, 36], 'diversity' => [38, 39, 40],
        'comms' => [42, 43, 44], 'property' => [46, 47, 48], 'staff' => [50, 51, 52],
    ];

    /** @var array<string, list<array{0: string, 1: string, 2: string, 3: string}>> objective, task, expected result, who */
    private const BANK = [
        'financial' => [
            ['Diversify income', 'Develop and approve a resource mobilisation strategy', 'Strategy approved by the Board', 'General Secretary and Finance Committee'],
            ['Build reserves', 'Set up a reserve fund covering three months of costs', 'Reserve account opened and funded', 'Treasurer'],
            ['Strengthen financial control', 'Commission an external audit of last year’s accounts', 'Audited accounts presented to the AGM', 'Finance Officer'],
            ['Grow local income', 'Launch a social enterprise pilot from an existing facility', 'Pilot running with a business plan', 'General Secretary'],
        ],
        'governance' => [
            ['Renew the Board', 'Run an induction and governance training for all Board members', 'Every Board member trained', 'Board Chairperson'],
            ['Plan succession', 'Adopt a succession plan for the Board and senior staff', 'Succession plan approved', 'Nominations Committee'],
            ['Meet regularly', 'Hold and minute four Board meetings in the year', 'Minutes of four meetings on file', 'Board Secretary'],
        ],
        'constitution' => [
            ['Update the constitution', 'Review the constitution against the World YMCA statements', 'Revised constitution adopted at the AGM', 'Constitution Committee'],
            ['Complete the policies', 'Approve a safeguarding policy and train staff on it', 'Policy approved; staff trained', 'Programmes Manager'],
            ['Protect people', 'Adopt a whistle-blowing and conflict of interest policy', 'Policies approved and shared', 'Board Secretary'],
        ],
        'me' => [
            ['Measure results', 'Set up a simple monitoring and evaluation framework with indicators', 'Framework in use for all programmes', 'M&E Officer'],
            ['Report on impact', 'Publish a short annual impact report', 'Report published and shared with partners', 'Communications Officer'],
            ['Use data', 'Collect participant data in one shared database', 'Database in use by every branch', 'M&E Officer'],
        ],
        'strategy' => [
            ['Plan ahead', 'Develop a five-year strategic plan aligned with Vision 2030', 'Strategic plan approved by the Board', 'General Secretary'],
            ['Review the plan', 'Hold a yearly review of the strategic plan with the Board', 'Review report on file', 'Board Chairperson'],
            ['Share the mission', 'Present the strategy to staff, volunteers and partners', 'Strategy presented in every branch', 'General Secretary'],
        ],
        'diversity' => [
            ['Bring young people in', 'Raise youth representation on the Board to at least 30%', 'Youth share of the Board at 30%', 'Nominations Committee'],
            ['Balance gender', 'Set and track gender balance targets for the Board and staff', 'Targets adopted and reported', 'Board Chairperson'],
            ['Grow youth leaders', 'Set up a youth council that advises the Board', 'Youth council meeting quarterly', 'Youth Coordinator'],
        ],
        'comms' => [
            ['Be visible', 'Refresh the website and keep it updated monthly', 'Website current, updated every month', 'Communications Officer'],
            ['Tell our story', 'Adopt a communications strategy with a yearly budget', 'Strategy and budget approved', 'Communications Officer'],
            ['Protect the brand', 'Register the YMCA name and logo locally', 'Registration certificate on file', 'General Secretary'],
        ],
        'property' => [
            ['Know our assets', 'Compile a register of all land and buildings with title deeds', 'Property register complete', 'Property Committee'],
            ['Use property well', 'Prepare a property development plan for the main site', 'Plan approved by the Board', 'Property Committee'],
            ['Insure our assets', 'Insure all buildings and vehicles', 'Insurance certificates on file', 'Finance Officer'],
        ],
        'staff' => [
            ['Clear roles', 'Write job descriptions for every staff position', 'Job description for each post', 'Human Resources Officer'],
            ['Develop people', 'Run a training plan for staff and volunteers', 'Training plan delivered', 'Human Resources Officer'],
            ['Value volunteers', 'Adopt a volunteer policy with recognition and induction', 'Volunteer policy approved', 'Volunteer Coordinator'],
        ],
    ];

    /** @var array<string, string> what a key focus area says, by category */
    private const FOCUS = [
        'financial' => 'Financial sustainability and reserves', 'governance' => 'Board renewal and succession',
        'constitution' => 'Policies and constitution', 'me' => 'Monitoring and reporting impact',
        'strategy' => 'Strategic planning', 'diversity' => 'Youth and gender balance in leadership',
        'comms' => 'Visibility and communications', 'property' => 'Property management', 'staff' => 'Staff and volunteer development',
    ];

    /**
     * Writes an invented ODP and returns the path of a temporary .xlsx (delete it after).
     *
     * @param  list<string>  $focus  the categories it concentrates on (most tasks, first deadlines)
     */
    public static function make(string $movementName, Carbon $start, array $focus, int $seed): string
    {
        $random = new Randomizer(new Mt19937($seed));
        $book = IOFactory::load((string) config('oha.odp_template'));
        $sheet = $book->getSheet(0);
        $end = $start->copy()->addMonths(12)->subDay();

        $sheet->setCellValue('D4', $movementName);
        $sheet->setCellValue('D6', $start->format('F Y').' to '.$end->format('F Y'));
        foreach (array_slice(array_values(array_unique([...$focus, 'financial', 'governance'])), 0, 5) as $i => $code) {
            $sheet->setCellValue('C'.(9 + $i), self::FOCUS[$code]);
        }

        foreach (self::ROWS as $code => $rows) {
            $tasks = $random->shuffleArray(self::BANK[$code]);
            // A focus area gets three tasks; the others one or two.
            $count = in_array($code, $focus, true) ? 3 : $random->getInt(1, 2);
            foreach (array_slice($tasks, 0, $count) as $i => [$objective, $task, $result, $who]) {
                $row = $rows[$i];
                $due = $start->copy()->addMonths($random->getInt(in_array($code, $focus, true) ? 2 : 4, 12))->endOfMonth();
                $sheet->setCellValue("C{$row}", $objective);
                $sheet->setCellValue("D{$row}", $result);
                $sheet->setCellValue("E{$row}", 'Minutes, reports and documents in the evidence library');
                $sheet->setCellValue("F{$row}", $task);
                $sheet->setCellValue("G{$row}", $due->format('M-y'));
                $sheet->setCellValue("H{$row}", $who);
                $sheet->setCellValue("I{$row}", $random->getInt(0, 1) === 1 ? 'Staff time' : 'Budget line in the annual plan');
            }
        }
        $sheet->setCellValue('C63', 'The General Secretary, with the AAYMCA assessor');

        $path = tempnam(sys_get_temp_dir(), 'demo-odp').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
