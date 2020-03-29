<?php

namespace App\Console\Commands;

use App\Models\Host;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Litipk\BigNumbers\Decimal;

class Hosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hosts:fetch {hosts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch hosts from siad, argument: collect hosts(1) or history for charts(0)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $collect_hosts = $this->argument('hosts');


        $hosts = Host::where('active', 1)->get();

        try {
            $client = new \GuzzleHttp\Client();
            $res = $client->request('GET', env('SIA_ADDRESS').'/hostdb/all');
            $response = json_decode($res->getBody(), true);
            foreach ($response['hosts'] as $host) {
                if (version_compare($host['version'], '1.3.7', '<')) {
                    echo "Skip {$host['version']}".PHP_EOL;
                    continue;
                }

                try {
                    $db_host = Host::firstOrNew(['key' => $host['publickey']['key']]);

                    $new = false;
                    if (empty($db_host->id)) {
                        $new = true;
                    }

                    $db_host->fill($host);
                    $db_host->algorithm = $host['publickey']['algorithm'];
                    $db_host->key = $host['publickey']['key'];

                    if(!empty($host['scorebreakdown'])) {
                        $score = 1;
						$step = 1;
                        foreach ($host['scorebreakdown'] as $key => $val) {
							/*	Fix exponents	*/
							if( ( $epos = strpos( $val, "E" ) ) > 0 ) {
								/*	determine operator	*/
								$operator = substr( $val, $epos+1, 1);
								$exp = substr( $val, $epos+2 );
								//echo 'Operator: ', $operator, '. Exponent: ', $exp;
								if( $operator == '-' )
									$val = $val * ( 10 ** $exp );
								elseif( $operator == '+' )
									$val = $val / ( 10 ** $exp );
							}
							$host['scorebreakdown'][$key] = $val;
							
                            if ($key == "score" || $key == "conversionrate") {
                                continue;
                            }

                            $score = $score*$val;
                        }
						
                        $host['scorebreakdown']['score'] = sprintf('%.30f', $score);
                        $db_host->score = json_encode($host['scorebreakdown']);
                    } else {
                        $db_host->score = json_encode([]);
                    }
					
					//foreach( $db_host->score as $key => $val ) {
					//	if( ( $epos = strpos( $val, "E" ) ) > 0 ) {
					//		/*	determine operator	*/
					//		$operator = substr( $val, $epos+1, 1);
					//		$exp = substr( $val, $epos+2 );
					//		//echo 'Operator: ', $operator, '. Exponent: ', $exp;
					//		if( $operator == '-' )
					//			$val = $val * ( 10 ** $exp );
					//		elseif( $operator == '+' )
					//			$val = $val / ( 10 ** $exp );
					//	}
					//	$db_host->score->$key = $val;
					//}
					
					if( $host['netaddress'] == 'prime.kingsley-muir.com:4282' ) {
						print_r( $db_host->score );
					}


                    $last_scan = end($host['scanhistory']);

                    $hostname = explode(':', $host['netaddress']);
                    $hostname = array_slice($hostname, 0, -1);
                    $hostname = implode(':', $hostname);

                    $db_host->host = (filter_var($hostname, FILTER_VALIDATE_IP)) ? $hostname:gethostbyname($hostname);
                    if ($last_scan['success']) {
                        $tz = substr($last_scan['timestamp'], -6);
                        $db_host->last_seen = strtotime(explode('.', $last_scan['timestamp'])[0].$tz);

                        //dump($tz, $last_scan['timestamp']);
                    }
                    $db_host->active = $last_scan['success'];

                    $db_host->save();

                    // add history only for active host OR add initial history row for new host
                    if ((!$collect_hosts && $db_host->active) || $new) {
                        $db_host->history()->create($host);
                        echo "History added".PHP_EOL;
                    }

                    echo "Saved {$db_host->netaddress}".PHP_EOL;
                } catch (\Exception $e) {
                    echo "err host". $e->getMessage();
                }

                Cache::put('wallet_online', true, 10);
            }
			
			$hosts_arr = $hosts->toArray();
			usort($hosts_arr, function ($a, $b) {
				$host_score = json_decode($a['score'], true);
				$a = (!empty($host_score['score'])) ? (float)$host_score['score']:0;

				$host_score = json_decode($b['score'], true);
				$b = (!empty($host_score['score'])) ? (float)$host_score['score']:0;

				return Decimal::fromFloat($b)->comp(Decimal::fromFloat($a));
			});

			$i = 1;
			foreach ($hosts_arr as $key => $host) {
				$host_ = Host::find($host['id']);
				$host_->rank = $i;
				$host_->save();
				$i++;
			}
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            Cache::put('wallet_online', false, 10);
            echo $e->getMessage();
        }
    }
}
