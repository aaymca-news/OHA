<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A small ODP workbook laid out like AAYMCA's ODP template: the heading, the
 * organisation, the priorities table with its columns, one priority and its tasks.
 */
final class OdpWorkbook
{
    /**
     * @param  list<list<string|null>>|null  $rows
     */
    public static function make(?array $rows = null, string $extra = ''): string
    {
        $rows ??= [
            [null, 'ORGANISATIONAL DEVELOPMENT PLAN'],
            [],
            [null, 'ORGANISATION: ', 'ZAMBIA YMCA'],
            [],
            ['№', 'OBJECTIVES', 'EXPECTED RESULTS', 'MEANS OF VERIFICATION', 'TASKS', 'TO COMPLETE THE TASK BY DEADLINE', 'RESPONSIBLE ONES'],
            ['STRATEGIC PRIORITY ONE (1):', 'FINANCIAL STABILITY'],
            ['1.1', 'Develop a fundraising strategy'.$extra, 'A strategy with targets is adopted.', "i. Strategy document\nii. Board minutes", 'Draft, consult, adopt', 'June 2027', 'General Secretary'],
        ];

        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Sheet1')->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'odp').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
