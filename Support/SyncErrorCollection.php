<?php declare(strict_types=1);

namespace Salient\Sync\Support;

use Salient\Collection\AbstractTypedCollection;
use Salient\Console\Catalog\ConsoleLevel as Level;
use Salient\Console\ConsoleFormatter as Formatter;
use Salient\Core\Utility\Arr;
use Salient\Sync\Catalog\SyncErrorType as ErrorType;
use JsonSerializable;

/**
 * A collection of SyncError objects
 *
 * @extends AbstractTypedCollection<int,SyncError>
 */
final class SyncErrorCollection extends AbstractTypedCollection implements JsonSerializable
{
    /**
     * Get a JSON:API-compatible representation of the errors
     *
     * @return array<array{code:string,title:string,detail:string,meta:array{level:string,count:int,seen:int,values:mixed[]}}>
     */
    public function toSummary(): array
    {
        $summary = [];
        /** @var SyncError $error */
        foreach ($this as $error) {
            $code = $error->getCode();
            $message = $error->Message;
            $key = "$code.$message";
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'code' => $code,
                    'title' => ErrorType::toName($error->ErrorType),
                    'detail' => $message,
                    'meta' => [
                        'level' => Level::toName($error->Level),
                        'count' => 0,
                        'seen' => 0,
                    ],
                ];
            }
            $summary[$key]['meta']['values'][] = Arr::unwrap($error->Values);
            $summary[$key]['meta']['count']++;
            $summary[$key]['meta']['seen'] += $error->Count;
        }
        ksort($summary);

        return array_values($summary);
    }

    /**
     * Get a human-readable representation of the errors
     */
    public function toString(bool $withMarkup = false): string
    {
        $b = $withMarkup ? '__' : '';
        $em = $withMarkup ? '_' : '';
        $d = $withMarkup ? '~~' : '';

        $summary = $this->toSummary();
        $lines = [];
        $separator = "\n  ";
        foreach ($summary as $error) {
            $values = Arr::toScalars($error['meta']['values']);

            if ($withMarkup) {
                foreach ($values as &$value) {
                    $value = Formatter::escapeTags((string) $value);
                }
            }

            $lines[] = sprintf(
                "{$d}{{$d}{$em}%d{$em}{$d}}{$d} {$b}{$em}%s{$em}{$b} {$d}[{$d}{$b}%s{$b}{$d}]{$d} {$d}({$d}{$em}'%s'{$em}{$d}){$d}:%s%s",
                $error['meta']['seen'],
                $error['title'],
                $error['meta']['level'],
                $error['detail'],
                $separator,
                implode($separator, $values),
            );
        }

        return implode("\n", $lines);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @return array<array{code:string,title:string,detail:string,meta:array{level:string,count:int,seen:int,values:mixed[]}}>
     */
    public function jsonSerialize(): array
    {
        return $this->toSummary();
    }
}
