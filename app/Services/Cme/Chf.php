<?php
/**
 * Created by PhpStorm.
 * User: dev
 * Date: 17.08.16
 * Time: 17:18
 */

namespace App\Services\Cme;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class Chf extends Base
{
    public $start_index_call = 'SWISS FRNC CALL';
    public $end_index_call = 'WKSF-1S-C';
    public $start_index_put = 'SWISS FRNC PUT';
    public $end_index_put = 'WKSF-1S-P';

    public $new_page_key_call = 'SWISS FRNC CALL (';
    public $new_page_key_put = 'SWISS FRNC PUT (';
    
    public function __construct($date = null)
    {
        $this->pair = self::PAIR_CHF;

        parent::__construct($date);
        
        $this->pair_with_major = self::PAIR_USD.self::PAIR_CHF;
        $this->option = DB::table($this->table)
            ->where(
                [
                    ['_expiration', '>', $this->option_date],
                    ['_symbol', '=', $this->pair_with_major]
                ]
            )
            ->orderBy('_expiration')
            ->first();
        
        $this->table_day = 'cme_day_'.strtolower($this->pair_with_major);
        $this->table_total = 'cme_bill_'.strtolower($this->pair_with_major).'_total';
        $this->table_month = 'cme_bill_'.strtolower($this->pair_with_major).'_'.strtolower($this->option->_option_month);
        $this->cme_file_path = Storage::disk('public')->getDriver()->getAdapter()->getPathPrefix() . env('CME_PARSER_SAVE_FOLDER') . '/' . date('Y', $this->option_date) . '/' . env('CME_BULLETIN_FOLDER_PREFIX') . date('Ymd', $this->option_date) . '/'; 
    }

    public function parse()
    {
        if (!empty($this->option) && is_file($this->cme_file_path . $this->files[self::CME_BULLETIN_TYPE_CALL]) && is_file($this->cme_file_path . $this->files[self::CME_BULLETIN_TYPE_PUT])) {
            $data_call = $this->getRows($this->cme_file_path . $this->files[self::CME_BULLETIN_TYPE_CALL], $this->option->_option_month, self::CME_BULLETIN_TYPE_CALL);
            $data_put = $this->getRows($this->cme_file_path . $this->files[self::CME_BULLETIN_TYPE_PUT], $this->option->_option_month, self::CME_BULLETIN_TYPE_PUT);

            $max_oi_call = 0;
            $max_oi_put = 0;
            if (count($data_call) !== 0) {
                $max_oi_call = $this->addCmeData($this->option_date, $data_call, self::CME_BULLETIN_TYPE_CALL);
            } else {
                Log::warning('Не смогли получить основные данные дефолтным методом.', [ 'type' => self::CME_BULLETIN_TYPE_CALL, 'pair' => $this->pair, 'date' => $this->option_date ]);
            }
            if (count($data_put) !== 0) {
                $max_oi_put = $this->addCmeData($this->option_date, $data_put, self::CME_BULLETIN_TYPE_PUT);
            } else {
                Log::warning('Не смогли получить основные данные дефолтным методом.', [ 'type' => self::CME_BULLETIN_TYPE_PUT, 'pair' => $this->pair, 'date' => $this->option_date ]);
            }

            if (DB::table($this->table_month)->where('_date', '=', $this->option_date)->first()) {
                $this->addTotalCmeData($this->option->_id, $this->option_date, $data_call, $data_put);
                $this->updatePairPrints($this->option_date, ($max_oi_call > $max_oi_put ? $max_oi_call : $max_oi_put));
                $this->updateCmeDayTable($this->option_date, $data_call, $data_put, $this->pair_with_major);
                $this->updateCvs($this->option_date, $data_call, $data_put);
            }

            $this->finish($this->option->_id, $this->option_date);
        } else {
            Log::warning('Нет файлов на дату.', [ 'pair' => $this->pair, 'date' => $this->option_date ]);
        }

        return true;
    }

    protected function prepareItemFromParse($key, $data) 
    {
        $result = array();

        switch ($key) {
            case 0:
                $data_arr = explode(' ', $data);
                
                if (count($data_arr) == 9) {
                    $result = $data_arr;
                } else if (count($data_arr) == 8) {
                    if (in_array($data_arr[count($data_arr) - 1], array('+', '-'))) {
                        $result = array_merge(array_slice($data_arr, 0, 5), array('+'), array_slice($data_arr, 5, 3));
                    } else {
                        $result = array_merge($data_arr, array('+'));
                    }
                } else if (count($data_arr) == 7) {
                    $result = array_merge(array_slice($data_arr, 0, 5), array('+'), array_slice($data_arr, 5, 2), array('+'));
                } else {
                    // error
                }

                break;
            
            case 1:
                $data_arr = explode(' ', $data);
                
                if (count($data_arr) == 4) {
                    $result = $data_arr;
                } else {
                    
                }

                break;
            
            case 2:
                $data_arr = explode(' ', $data);

                if (count($data_arr) == 2) {
                    $result = $data_arr;
                }

                break;
            
            case 3:
                $data_arr = explode(' ', $data);

                if (count($data_arr) == 1) {
                    $result = $data_arr;
                }

                break;
        }
        
        return $result;
    }
    
    protected function prepareArrayFromPdf($data)
    {
        $strike = (int)$data[15];
        $oi = (int)$data[7];
        $coi = (($data[8] == '+') ? 1 : -1 )*(int)$data[10];
        $delta = (float)$data[13];
        $reciprocal = (float)$data[4];
        $volume = (int)$data[6];

        return array(
            'strike' => $strike,
            'reciprocal' => $reciprocal,
            'volume' => $volume,
            'oi' => $oi,
            'coi' => $coi,
            'delta' => $delta,
            'cvs' => null,
            'cvs_balance' => null,
            'print' => null
        );
    }
}