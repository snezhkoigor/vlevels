<?php

namespace App\Console\Commands;

use App\Services\Cme\Base;
use App\Services\Cme\Chu;
use Illuminate\Console\Command;

class ParseChu extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parseChu';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse information from *.pdf file by CHF pair (Europe style)';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $chf = new Chu();

        $option = $chf->getOption();
        $content = @file_get_contents($chf->cme_file_path . env('CME_JSON_FILE_NAME'));

        if (!empty($content)) {
            $content = json_decode($content, true);

            if (count($content) !== 0) {
                foreach ($content as $month => $month_data) {
                    $option_by_month = $chf->getOptionDataByMonth($month);

                    if (!empty($option_by_month)) {
                        $other_month = new Chu($option_by_month->_expiration);

                        if ($option->_option_month != $option_by_month->_option_month) {
                            $other_month->update_day_table = false;
                            $other_month->update_fractal_field_table = false;
                        }

                        if (!empty($month_data[Base::CME_BULLETIN_TYPE_CALL]) && !empty($month_data[Base::CME_BULLETIN_TYPE_PUT])) {
                            $other_month->parse(true, array_values($month_data[Base::CME_BULLETIN_TYPE_CALL]), array_values($month_data[Base::CME_BULLETIN_TYPE_PUT]));
                        }

                        unset($option_by_month);
                        unset($other_month);
                    }
                }
            }
        }
    }
}
