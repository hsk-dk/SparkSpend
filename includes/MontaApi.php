<?php
/**
 * What the sync needs from Monta - and nothing more, so the import can be tested with a fake.
 * Implemented by MontaClient (curl).
 */
interface MontaApi
{
    /** @throws MontaApiException when no token can be had */
    public function token(): string;

    /**
     * All charges in the window (every page).
     * @return array<int,array>|null  null = rate-limited or the network is down: try again next run
     * @throws MontaApiException on a response that will not get better by waiting
     */
    public function charges(string $token, string $fromDate, string $toDate): ?array;
}
