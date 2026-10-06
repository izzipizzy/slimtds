<?php

declare(strict_types=1);

namespace App\Mcp;

final class Instructions
{
    public const TEXT = <<<'TXT'
        slimTDS is a traffic distribution system: visitors hit a campaign link, a flow's filters pick an offer, the click is logged, and affiliate postbacks arrive later as conversions. All tools here are read-only.

        Start with list_campaigns, then traffic_summary for the campaign and period in question, then drill down with traffic_breakdown one dimension at a time. Use traffic_timeline to see when something changed, list_clicks and visitor_journey for individual cases, conversions_summary for revenue, pixel_summary for what happened on the landers before the click. Call get_campaign to see a campaign's flows, filters and trash-mode fallback before recommending any change to its routing.

        Bots and trash (clicks no flow matched) are excluded by default and reported as separate counters; judge them apart from real traffic. CR is approved conversions over clicks, EPC is approved payout over clicks. Dates are in the instance time zone. IP addresses are masked unless the owner allowed full ones.
        TXT;
}
