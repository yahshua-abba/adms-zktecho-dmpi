<?php

namespace App\Http\Controllers;
use App\Models\Attendance;
use App\Models\DeviceCommand;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


class iclockController extends Controller
{

   public function __invoke(Request $request)
   {

   }

    // handshake
public function handshake(Request $request)
{
    // Any contact means the device is alive.
    $this->touchOnline($request->input('SN'));

    // A device handshakes ~every 30s; logging every bare heartbeat would
    // dominate device_log (~2,880 rows/device/day). Only record meaningful
    // contacts — config/option requests or anything carrying a body.
    $meaningful = $request->getContent() !== ''
        || $request->filled('options')
        || $request->filled('option');

    if ($meaningful) {
        DB::table('device_log')->insert([
            'url' => json_encode($request->all()),
            'data' => $request->getContent(),
            'sn' => $request->input('SN'),
            'option' => $request->input('option'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $r = "GET OPTION FROM: {$request->input('SN')}\r\n" .
         "Stamp=9999\r\n" .
         "OpStamp=" . time() . "\r\n" .
         "ErrorDelay=60\r\n" .
         "Delay=30\r\n" .
         "ResLogDay=18250\r\n" .
         "ResLogDelCount=10000\r\n" .
         "ResLogCount=50000\r\n" .
         "TransTimes=00:00;14:05\r\n" .
         "TransInterval=1\r\n" .
         "TransFlag=1111000000\r\n" .
        //  "TimeZone=7\r\n" .
         "Realtime=1\r\n" .
         "Encrypt=0";

    return $r;
}
        //$r = "GET OPTION FROM:%s{$request->SN}\nStamp=".strtotime('now')."\nOpStamp=1565089939\nErrorDelay=30\nDelay=10\nTransTimes=00:00;14:05\nTransInterval=1\nTransFlag=1111000000\nTimeZone=7\nRealtime=1\nEncrypt=0\n";
    // implementasi https://docs.nufaza.com/docs/devices/zkteco_attendance/push_protocol/
    // setting timezone
    // request absensi
    public function receiveRecords(Request $request)
    {
        // A punch push is a live contact — keep online status fresh.
        $this->touchOnline($request->input('SN'));

        //DB::connection()->enableQueryLog();
        $content['url'] = json_encode($request->all());
        $content['data'] = $request->getContent();
        $content['created_at'] = now();
        $content['updated_at'] = now();
        DB::table('finger_log')->insert($content);
        try {
            // $post_content = $request->getContent();
            //$arr = explode("\n", $post_content);
            $arr = preg_split('/\\r\\n|\\r|,|\\n/', $request->getContent());
            //$tot = count($arr);
            $tot = 0;
            //operation log
            if($request->input('table') == "OPERLOG"){
                // $tot = count($arr) - 1;
                foreach ($arr as $rey) {
                    if(isset($rey)){
                        $tot++;
                    }
                }
                return "OK: ".$tot;
            }
            //attendance
            // The device's IN/OUT direction is frozen onto each punch as it
            // arrives, so later edits to the device's direction never rewrite
            // past punches (in the UI or in what gets pushed to payroll).
            $direction = \App\Models\Device::where('no_sn', $request->input('SN'))->value('direction');
            foreach ($arr as $rey) {
                // $data = preg_split('/\s+/', trim($rey));
                if(empty($rey)){
                    continue;
                }
                    // $data = preg_split('/\s+/', trim($rey));
                    $data = explode("\t",$rey);
                    //dd($data);
                    $q['sn'] = $request->input('SN');
                    $q['table'] = $request->input('table');
                    $q['stamp'] = $request->input('Stamp');
                    $q['employee_id'] = $data[0];
                    $q['timestamp'] = $data[1];
                    $q['status1'] = $this->validateAndFormatInteger($data[2] ?? null);
                    $q['status2'] = $this->validateAndFormatInteger($data[3] ?? null);
                    $q['status3'] = $this->validateAndFormatInteger($data[4] ?? null);
                    $q['status4'] = $this->validateAndFormatInteger($data[5] ?? null);
                    $q['status5'] = $this->validateAndFormatInteger($data[6] ?? null);
                    $q['log_type'] = \App\Sync\LogType::resolve($direction, (int) $q['status1']);
                    $q['created_at'] = now();
                    $q['updated_at'] = now();
                    //dd($q);
                    // insertOrIgnore + the (sn, employee_id, timestamp) unique index
                    // drops duplicates from device re-sends (e.g. after a reconnect).
                    // Still count the line so the device considers it acknowledged.
                    DB::table('attendances')->insertOrIgnore($q);
                    $tot++;
                // dd(DB::getQueryLog());
            }
            return "OK: ".$tot;
        } catch (Throwable $e) {
            $data['error'] = $e;
            DB::table('error_log')->insert($data);
            report($e);
            return "ERROR: ".$tot."\n";
        }
    }
    public function test(Request $request)
    {
                $log['data'] = $request->getContent();
                DB::table('finger_log')->insert($log);
    }
    // Device polls here for queued commands; we hand back pending ones as
    // "C:<id>:<body>" lines and mark them sent.
    public function getrequest(Request $request)
    {
        $sn = $request->input('SN');
        $this->touchOnline($sn); // polling for commands is a live contact too

        $commands = DeviceCommand::where('device_sn', $sn)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        if ($commands->isEmpty()) {
            return "OK";
        }

        $lines = [];
        foreach ($commands as $command) {
            $lines[] = "C:{$command->id}:{$command->body}";
            $command->update(['status' => 'sent', 'sent_at' => now()]);
        }

        return implode("\n", $lines);
    }

    // Device reports command results here: "ID=<id>&Return=<code>&CMD=<cmd>"
    // (possibly several lines). Return=0 means success.
    public function devicecmd(Request $request)
    {
        foreach (preg_split('/\r\n|\r|\n/', trim($request->getContent())) as $line) {
            if (trim($line) === '') {
                continue;
            }
            parse_str(trim($line), $parts);
            if (! isset($parts['ID'])) {
                continue;
            }
            $return = $parts['Return'] ?? null;
            DeviceCommand::where('id', $parts['ID'])->update([
                'status' => ((int) $return === 0) ? 'done' : 'failed',
                'return_code' => is_null($return) ? null : (int) $return,
                'done_at' => now(),
            ]);
        }

        return "OK";
    }
    // Mark a device as just-contacted (drives the Online/Offline status).
    private function touchOnline(?string $sn): void
    {
        if (! empty($sn)) {
            DB::table('devices')->updateOrInsert(
                ['no_sn' => $sn],
                ['online' => now()]
            );
        }
    }

    private function validateAndFormatInteger($value)
    {
        return isset($value) && $value !== '' ? (int)$value : null;
        // return is_numeric($value) ? (int) $value : null;
    }

}
