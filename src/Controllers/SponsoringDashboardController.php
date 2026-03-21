<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\SponsoringContact;
use Carbon\Carbon;

class SponsoringDashboardController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        $totalActive = Sponsor::where('status', 'active')->count();

        $totalAmount = (float) Sponsorship::where('status', 'active')->sum('amount');

        $pipeline = (float) Sponsorship::where('status', 'negotiating')->sum('amount');

        $today = date('Y-m-d');

        $openFollowUps = SponsoringContact::where('follow_up_done', 0)
            ->whereNotNull('follow_up_date')
            ->where('follow_up_date', '<=', $today)
            ->count();

        $in7Days = Carbon::now()->addDays(7)->format('Y-m-d');

        $upcomingFollowUps = SponsoringContact::where('follow_up_done', 0)
            ->whereNotNull('follow_up_date')
            ->where('follow_up_date', '<=', $in7Days)
            ->with(['sponsor', 'user', 'sponsorship'])
            ->orderBy('follow_up_date')
            ->get();

        $recentContacts = SponsoringContact::with(['sponsor', 'user'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return $this->view->render($response, 'sponsoring/dashboard.twig', [
            'total_active'      => $totalActive,
            'total_amount'      => $totalAmount,
            'pipeline'          => $pipeline,
            'open_follow_ups'   => $openFollowUps,
            'upcoming_follow_ups' => $upcomingFollowUps,
            'recent_contacts'   => $recentContacts,
            'active_nav'        => 'sponsoring',
        ]);
    }
}
