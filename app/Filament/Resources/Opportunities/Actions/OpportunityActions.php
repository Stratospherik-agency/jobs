<?php

namespace App\Filament\Resources\Opportunities\Actions;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Ignorer en un clic une annonce qui ne convient pas, et la rétablir au besoin.
 */
class OpportunityActions
{
    /** Seules les annonces sans candidature partie peuvent être ignorées. */
    public static function canIgnore(Opportunity $o): bool
    {
        return in_array($o->status, [OpportunityStatus::ToEvaluate, OpportunityStatus::ToApply, OpportunityStatus::InPreparation], true)
            && ! $o->application?->isSent();
    }

    public static function ignore(): Action
    {
        return Action::make('ignore')
            ->label('Ignorer')
            ->icon('heroicon-o-eye-slash')
            ->color('gray')
            ->visible(fn (Opportunity $record) => self::canIgnore($record))
            ->action(function (Opportunity $record) {
                $record->update(['status' => OpportunityStatus::Discarded]);
                Notification::make()
                    ->title('Annonce ignorée')
                    ->body($record->company.' – '.$record->title.'. Retrouve-la avec le filtre « Statut : Écartée ».')
                    ->send();
            });
    }

    public static function restore(): Action
    {
        return Action::make('restore')
            ->label('Rétablir')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (Opportunity $record) => $record->status === OpportunityStatus::Discarded)
            ->action(function (Opportunity $record) {
                $record->update(['status' => OpportunityStatus::ToEvaluate]);
                Notification::make()->title('Annonce rétablie')->success()->send();
            });
    }
}
