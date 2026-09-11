<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Models\PayoutAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * Hands an identity document to Stripe on the seller's behalf.
 *
 * The document passes through this application and is not kept by it
 * (ADR 0015). The upload's temporary file is streamed to Stripe's Files API,
 * and PHP deletes it when the request ends. Nothing is written to a disk of
 * ours, and the file id Stripe gives back is attached to the account and not
 * stored either.
 *
 * Uploaded **as the connected account**, with `stripe_account`, because the
 * document belongs to the seller's account rather than to the platform's.
 *
 * A passport has one side and most ID cards have two, so the back is optional
 * and Stripe says whether it was needed.
 */
final class AttachIdentityDocument
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly SyncPayoutAccount $sync,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(PayoutAccount $account, UploadedFile $front, ?UploadedFile $back): PayoutAccount
    {
        $document = ['front' => $this->upload($account, $front, 'front')];

        if ($back instanceof UploadedFile) {
            $document['back'] = $this->upload($account, $back, 'back');
        }

        try {
            $updated = $this->stripe->accounts->update($account->stripe_account_id, [
                'individual' => ['verification' => ['document' => $document]],
            ]);
        } catch (InvalidRequestException $refusal) {
            throw $this->besideTheSide($refusal);
        }

        return $this->sync->handle($account, $updated);
    }

    /**
     * @param  'front'|'back'  $side
     *
     * @throws ValidationException
     */
    private function upload(PayoutAccount $account, UploadedFile $file, string $side): string
    {
        $path = $file->getRealPath();
        $contents = $path === false ? false : fopen($path, 'rb');

        if ($contents === false) {
            throw new RuntimeException('The uploaded document could not be read from its temporary file.');
        }

        try {
            return $this->stripe->files->create(
                ['purpose' => 'identity_document', 'file' => $contents],
                ['stripe_account' => $account->stripe_account_id],
            )->id;
        } catch (InvalidRequestException $refusal) {
            // Stripe read the file and would not take it: too large, or not an
            // image it can use. Either way it is about this side.
            throw ValidationException::withMessages([$side => $refusal->getMessage()]);
        } finally {
            fclose($contents);
        }
    }

    /**
     * Stripe refusing to attach one side, put beside that side.
     *
     * Anything else it objects to is in parameters this class wrote rather
     * than in what the seller sent, and is rethrown as this application's.
     */
    private function besideTheSide(InvalidRequestException $refusal): ValidationException|InvalidRequestException
    {
        $parameter = (string) $refusal->getStripeParam();

        foreach (['front', 'back'] as $side) {
            if (str_ends_with($parameter, "[{$side}]")) {
                return ValidationException::withMessages([$side => $refusal->getMessage()]);
            }
        }

        return $refusal;
    }
}
