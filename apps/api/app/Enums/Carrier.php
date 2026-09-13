<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who is carrying a parcel, when the shop says (ADR 0049).
 *
 * **A list rather than free text, and the reason is the link.** A tracking
 * number a buyer has to copy into a search engine is most of the way to
 * useless; one that is a link is the whole point of collecting it. A link needs
 * a known carrier, and a string somebody typed is not one.
 *
 * **Membership is the same question `PayoutCountry` asks**: the platform is in
 * Finland and its shops trade around the Nordics and Europe, so these are the
 * carriers those shops actually hand parcels to, plus the three globals that
 * turn up on anything crossing a border.
 *
 * A seller using something not on this list still marks the order sent, and may
 * still give a number - it simply arrives as text rather than as a link. That
 * is why `carrier` is nullable and this enum has no `Other` case: "other" is
 * the absence of a carrier we can link to, and a case for it would be a value
 * that means "ignore this value".
 */
enum Carrier: string
{
    case PostNord = 'postnord';
    case Posti = 'posti';
    case Bring = 'bring';
    case Matkahuolto = 'matkahuolto';
    case DHL = 'dhl';
    case UPS = 'ups';
    case FedEx = 'fedex';

    /** What a person calls it, which is not what the column stores. */
    public function label(): string
    {
        return match ($this) {
            self::PostNord => 'PostNord',
            self::Posti => 'Posti',
            self::Bring => 'Bring',
            self::Matkahuolto => 'Matkahuolto',
            self::DHL => 'DHL',
            self::UPS => 'UPS',
            self::FedEx => 'FedEx',
        };
    }

    /**
     * Where to follow the parcel.
     *
     * **The API answers this rather than the browser**, because a URL template
     * per carrier is a rule, and a copy of it in the frontend is the copy that
     * goes stale the day one of them changes their paths (root `CLAUDE.md`
     * section 4). It also means one place to fix when they do.
     *
     * The number is encoded rather than interpolated raw: it is a string a
     * seller typed, and it ends up in an href.
     */
    public function trackingUrl(string $number): string
    {
        $number = rawurlencode($number);

        return match ($this) {
            self::PostNord => "https://www.postnord.se/en/track-and-trace?shipmentId={$number}",
            self::Posti => "https://www.posti.fi/en/tracking#/lahetys/{$number}",
            self::Bring => "https://tracking.bring.com/tracking/{$number}",
            self::Matkahuolto => "https://www.matkahuolto.fi/en/tracking?parcelNumber={$number}",
            self::DHL => "https://www.dhl.com/en/express/tracking.html?AWB={$number}",
            self::UPS => "https://www.ups.com/track?tracknum={$number}",
            self::FedEx => "https://www.fedex.com/fedextrack/?trknbr={$number}",
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
