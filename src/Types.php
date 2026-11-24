<?php
/**
 * Every data shape the plugin passes between classes, declared once for static analysis.
 * Classes import them with @phpstan-import-type. (No runtime code.)
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook;

defined( 'ABSPATH' ) || exit;

/**
 * Shapes (see the file comment).
 *
 * @phpstan-type SeasonDate array{row_id: int, start: string, end: string, days: list<int>}
 * @phpstan-type Season array{id: int, name: string, priority: string, dates: list<SeasonDate>}
 * @phpstan-type Seasons array<int, Season>
 * @phpstan-type Rate array{id: int, type: string, amount: string, nights: int, accoms: list<int>, seasons: list<int>, rules: list<int>}
 * @phpstan-type Resa array{id: int, accom_id: int, accom_num: int, check_in: string, check_out: string, status: string, origin: string, synchro_id: string}
 * @phpstan-type Block array{id: int, accom_id: int, accom_num: int, from_date: string, to_date: string, linked_resa_id: int, is_prepa_time: bool, uid: string}
 * @phpstan-type Rows array{reservations: list<Resa>, blocks: list<Block>}
 * @phpstan-type HmNight array{price: int|null, closed: bool, available: int, min_stay: int|null, max_stay: int|null, cta: bool, ctd: bool}
 * @phpstan-type HmRates array<string, array<string, HmNight>>
 * @phpstan-type Unit array{id: int, title: string, name: string, units: list<int>}
 * @phpstan-type UnitsMap array<int, Unit>
 * @phpstan-type Context array{today: string, nights: list<string>, units: UnitsMap, accoms: list<int>, accom_room: array<int, string>, room_units: array<string, list<string>>, hybrids: array<string, list<string>>, seasons: Seasons, own_seasons: list<int>, rates: list<Rate>, rows: Rows}
 * @phpstan-type PricePlan array{seasons: list<array{key: string, base: int, ranges: list<array{0: string, 1: string}>, rates: list<array{amount: string, accoms: list<int>}>}>, foreign_high: list<string>, no_season: list<string>, last_price: array<string, int>, prices: array<int, array<string, string>>}
 * @phpstan-type BlockPlan array{wanted: array<string, list<string>>, mismatches: list<array{room: string, night: string, missing: int, candidates: list<string>}>}
 * @phpstan-type BrakeResult array{verdict: string, changed: int, total: int}
 * @phpstan-type Guest array{first_name: string, last_name: string, email: string, phone: string, country: string}
 * @phpstan-type BookingPayment array{ref: string, amount: int, refunded: int, gateway: string}
 * @phpstan-type Booking array{id: int, accom_id: int, accom_num: int, check_in: string, check_out: string, guests: int, status: string, total: int, number: string, guest: Guest, payments: list<BookingPayment>}
 * @phpstan-type SentPayment array{hm_id: string, amount: int}
 * @phpstan-type Sent array{uuid: string, created: bool, guest_id: string, status: string, room: string, bed: string, nights: array<string, int>, guest_hash: string, payments: array<string, SentPayment>, blocked: string}
 * @phpstan-type Op array{op: string, data: array<string, mixed>}
 */
final class Types {
}
