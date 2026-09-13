<?php
declare(strict_types=1);
namespace App\Media\Subtitles;

/** Reuses observed alignment only; no word times are estimated. */
final class TranscriptRevision
{
    public static function merge(Transcript $edited, ?Transcript $original, bool $sameInterval): Transcript
    {
        if (!$sameInterval || $original === null || $edited->durationMs() !== $original->durationMs()) return $edited;
        $byTime=[];
        foreach ($original->cues() as $cue) $byTime[$cue->startMs().':'.$cue->endMs()]=$cue;
        $cues=[];
        foreach ($edited->cues() as $cue) {
            $old=$byTime[$cue->startMs().':'.$cue->endMs()] ?? null;
            $words=[];
            if ($old !== null && $old->words() !== []) {
                if ($old->text() === $cue->text()) $words=$old->words();
                else {
                    $tokens=preg_split('/\s+/u',trim($cue->text()),-1,PREG_SPLIT_NO_EMPTY);
                    $oldTokens=preg_split('/\s+/u',trim($old->text()),-1,PREG_SPLIT_NO_EMPTY);
                    if (count($tokens)===count($old->words()) && count($tokens)===count($oldTokens)) {
                        $words=$old->words();
                        foreach ($tokens as $i=>$token) {
                            if (mb_strlen($token,'UTF-8')>80) { $words=[]; break; }
                            $words[$i]['text']=$token;
                        }
                    }
                }
            }
            $cues[]=new SubtitleCue($cue->startMs(),$cue->endMs(),$cue->text(),$words);
        }
        return new Transcript($original->language(),$cues,$edited->durationMs());
    }
}
