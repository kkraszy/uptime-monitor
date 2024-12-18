<?php

namespace App\Console\Commands;

use App\Models\CustomerSite;
use App\Models\MonitoringLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotifyUser extends Command
{
    protected $signature = 'notify-user';

    protected $description = 'Notify user for website down';

    public function handle(): void
    {
        if (empty(config('services.telegram_notifier.token'))) {
            return;
        }

        $customerSites = CustomerSite::where('is_active', 1)->get();

        foreach ($customerSites as $customerSite) {
            if (!$customerSite->canNotifyUser()) {
                continue;
            }

            $responseTimes = MonitoringLog::query()
                ->where('customer_site_id', $customerSite->id)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get(['response_time', 'created_at', 'status_code']);

            $responseTimeAverage = $responseTimes->avg('response_time');

            $statusCodes =  $responseTimes->avg('status_code');

            $this->info($responseTimes->first()->url . ' :' . $statusCodes);

            if ($responseTimeAverage >= ($customerSite->down_threshold * 0.9) || in_array($statusCodes, [500, 404, 503])) {

                $this->notifyUser($customerSite, $responseTimes);
                $customerSite->last_notify_user_at = Carbon::now();
                $customerSite->save();
            }
        }

        $this->info('Done!');
    }

    private function notifyUser(CustomerSite $customerSite, Collection $responseTimes): void
    {
        if (is_null($customerSite->owner)) {
            Log::channel('daily')->info('Missing customer site owner', $customerSite->toArray());
            return;
        }

        $users = User::all();

        foreach ($users as $user) {
            $telegramChatId = $user->telegram_chat_id;

            if (empty($telegramChatId)) {
                Log::channel('daily')->info('Missing telegram_chat_id form owner', $customerSite->toArray());
                continue;
            }

            $endpoint = 'https://api.telegram.org/bot' . config('services.telegram_notifier.token') . '/sendMessage';
            $text = "";
            $text .= "Monitoring Moonbite - problem ze stroną: ".$customerSite->name;
            $text .= "\n\n" . $customerSite->name . ' (' . $customerSite->url . ')';
            $text .= "\nOstatnie 5 odpytań:";
            $text .= "\n";
            foreach ($responseTimes as $responseTime) {
                $text .= $responseTime->created_at->format('H:i:s') . ' code:' . $responseTime->status_code . '  ' . $responseTime->response_time . ' ms';
                $text .= "\n";
            }
            $text .= "\nSprawdź:";
            $text .= "\n" . route('customer_sites.show', [$customerSite->id]);
            Http::post($endpoint, [
                'chat_id' => $telegramChatId,
                'text' => $text,
            ]);

            Log::channel('daily')->info('Send message to: '.$telegramChatId);
        }
    }
}
