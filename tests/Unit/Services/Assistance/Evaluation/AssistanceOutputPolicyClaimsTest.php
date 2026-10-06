<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\Policies\AssistanceOutputPolicy;

it('recognizes an answer that says a change was made', function (string $text): void {
    expect(AssistanceOutputPolicy::claimsChange($text))->toBeTrue();
})->with([
    'I have updated your layout.',
    "I've set the layout to cards.",
    'The change has been applied.',
    'Your preference was saved.',
    'Lists are now enabled as cards.',
    'Ho modificato il layout.',
    'Il layout è stato salvato.',
    'Le impostazioni sono state aggiornate.',
]);

it('lets an answer that only suggests, explains or asks through', function (string $text): void {
    expect(AssistanceOutputPolicy::claimsChange($text))->toBeFalse();
})->with([
    'I suggest cards; you can accept it below.',
    'If you accept, lists will open as cards.',
    'Nothing changes until you accept it.',
    'You can update it in Settings.',
    'Posso proporti le schede: accetta il suggerimento se ti piace.',
    'Puoi modificarlo dalle impostazioni.',
]);
