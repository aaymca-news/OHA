<?php

use App\Support\OdpChanges;
use Tests\Support\OdpWorkbook;

/*
 * What changed in a signed ODP, noted for Stage 2: cell by cell for a workbook.
 */

it('lists each cell whose value changed, with its value before and after', function () {
    $before = OdpWorkbook::make();
    $after = OdpWorkbook::make([
        [null, 'ORGANISATIONAL DEVELOPMENT PLAN'],
        [],
        [null, 'ORGANISATION: ', 'ZAMBIA YMCA'],
        [],
        ['№', 'OBJECTIVES', 'EXPECTED RESULTS', 'MEANS OF VERIFICATION', 'TASKS', 'TO COMPLETE THE TASK BY DEADLINE', 'RESPONSIBLE ONES'],
        ['STRATEGIC PRIORITY ONE (1):', 'FINANCIAL STABILITY'],
        ['1.1', 'Develop a fundraising strategy', 'A strategy with targets is adopted.', "i. Strategy document\nii. Board minutes", 'Draft, consult, adopt', 'March 2027', 'General Secretary'],
    ]);

    $changes = OdpChanges::between($before, $after, 'xlsx');

    expect($changes['kind'])->toBe('cells')
        ->and($changes['count'])->toBe(1)
        ->and($changes['cells'][0])->toBe(['sheet' => 'Sheet1', 'cell' => 'F7', 'before' => 'June 2027', 'after' => 'March 2027']);
});
