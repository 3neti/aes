<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    round: {
        code: string;
        name: string;
        precincts: Array<{
            code: string;
            label: string;
            status: string;
            deposited_ballots: number;
            vvdat_records: number;
            operations_board: {
                booths: {
                    active: number;
                    completed: number;
                    issued_unclaimed: number;
                    expired: number;
                };
                print_station: {
                    pending_pins: number;
                    redeemed_pins: number;
                    printed_awaiting_deposit: number;
                    deposited: number;
                    expired: number;
                };
                closeout: {
                    unresolved_voter_work: number;
                    can_close: boolean;
                    next_required_action: string;
                };
                timeline: Array<{
                    event_type: string;
                    occurred_at: string;
                    label: string;
                }>;
                privacy_notice: string;
            };
            journal: Array<{ event_type: string; occurred_at: string }>;
        }>;
    };
    privacyNotice: string;
}>();
</script>

<template>
    <main class="min-h-screen bg-stone-950 p-5 text-stone-100">
        <Head title="Simulation God Mode" />
        <section class="mx-auto max-w-7xl">
            <Link
                href="/election/play"
                class="text-sm font-bold text-yellow-300"
                >Exit facilitator view</Link
            >
            <p class="mt-6 text-sm font-bold text-yellow-300 uppercase">
                Simulation facilitator
            </p>
            <h1 class="mt-1 text-3xl font-bold">{{ round.name }}</h1>
            <p
                class="mt-3 max-w-4xl border-l-4 border-yellow-300 bg-stone-900 p-4 text-sm text-stone-200"
            >
                {{ privacyNotice }}
            </p>
            <section class="mt-6 grid gap-5 lg:grid-cols-3">
                <article
                    v-for="precinct in round.precincts"
                    :key="precinct.code"
                    class="border border-stone-700 bg-stone-900 p-5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold text-yellow-300">
                                {{ precinct.code }}
                            </p>
                            <h2 class="mt-1 text-xl font-bold">
                                {{ precinct.label }}
                            </h2>
                        </div>
                        <span
                            class="border border-stone-600 px-2 py-1 text-xs font-bold"
                            >{{ precinct.status }}</span
                        >
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-3">
                        <div class="bg-stone-800 p-3">
                            <dt class="text-xs text-stone-400">Deposits</dt>
                            <dd class="mt-1 text-2xl font-bold">
                                {{ precinct.deposited_ballots }}
                            </dd>
                        </div>
                        <div class="bg-stone-800 p-3">
                            <dt class="text-xs text-stone-400">
                                VVDAT records
                            </dt>
                            <dd class="mt-1 text-2xl font-bold">
                                {{ precinct.vvdat_records }}
                            </dd>
                        </div>
                    </dl>
                    <section
                        class="mt-5 border border-stone-700 bg-stone-950 p-4"
                    >
                        <p class="text-xs font-bold text-yellow-300">
                            Fixed booth handoff
                        </p>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div class="bg-stone-800 p-3">
                                <dt class="text-stone-400">Active booths</dt>
                                <dd class="mt-1 text-2xl font-bold">
                                    {{
                                        precinct.operations_board.booths.active
                                    }}
                                </dd>
                            </div>
                            <div class="bg-stone-800 p-3">
                                <dt class="text-stone-400">Pending PINs</dt>
                                <dd class="mt-1 text-2xl font-bold">
                                    {{
                                        precinct.operations_board.print_station
                                            .pending_pins
                                    }}
                                </dd>
                            </div>
                            <div class="bg-stone-800 p-3">
                                <dt class="text-stone-400">PINs claimed</dt>
                                <dd class="mt-1 text-2xl font-bold">
                                    {{
                                        precinct.operations_board.print_station
                                            .redeemed_pins
                                    }}
                                </dd>
                            </div>
                            <div class="bg-stone-800 p-3">
                                <dt class="text-stone-400">
                                    Printed, not deposited
                                </dt>
                                <dd class="mt-1 text-2xl font-bold">
                                    {{
                                        precinct.operations_board.print_station
                                            .printed_awaiting_deposit
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            class="mt-3 border-l-4 p-3 text-sm"
                            :class="
                                precinct.operations_board.closeout.can_close
                                    ? 'border-emerald-400 bg-emerald-950 text-emerald-100'
                                    : 'border-yellow-300 bg-yellow-950 text-yellow-100'
                            "
                        >
                            {{
                                precinct.operations_board.closeout
                                    .next_required_action
                            }}
                        </p>
                    </section>
                    <ol
                        class="mt-5 space-y-3 border-t border-stone-700 pt-4 text-sm"
                    >
                        <li
                            v-for="event in precinct.operations_board.timeline"
                            :key="`${event.occurred_at}-${event.event_type}`"
                        >
                            <strong class="block">{{ event.label }}</strong
                            ><span class="text-stone-400">{{
                                event.occurred_at
                            }}</span>
                        </li>
                    </ol>
                </article>
            </section>
        </section>
    </main>
</template>
