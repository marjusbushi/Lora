<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CheckAvailabilityTool;
use App\Mcp\Tools\CreatePriceProposalTool;
use App\Mcp\Tools\ExecuteApprovedActionTool;
use App\Mcp\Tools\GetGuestConversationTool;
use App\Mcp\Tools\GetHotelContextTool;
use App\Mcp\Tools\GetPricingCalendarTool;
use App\Mcp\Tools\GetReservationContextTool;
use App\Mcp\Tools\PrepareGuestReplyTool;
use App\Mcp\Tools\SearchReservationsTool;
use Laravel\Mcp\Server;

class LoraHotelServer extends Server
{
    protected string $name = 'Lora PMS Hotel Assistant';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        You are connected to exactly one Lora PMS hotel and must never infer or request access to another tenant. Read current hotel data before answering operational questions. Never expose identity documents, payment credentials, or unrelated guest records. Use minimum necessary guest data. Availability and prices are live and may change. Guest replies and price changes always require a proposal preview and explicit human confirmation before execute-approved-action. Never claim an external message or price was changed until the execution tool returns success.
    MARKDOWN;

    protected array $tools = [
        GetHotelContextTool::class,
        SearchReservationsTool::class,
        GetReservationContextTool::class,
        CheckAvailabilityTool::class,
        GetGuestConversationTool::class,
        GetPricingCalendarTool::class,
        PrepareGuestReplyTool::class,
        CreatePriceProposalTool::class,
        ExecuteApprovedActionTool::class,
    ];
}
