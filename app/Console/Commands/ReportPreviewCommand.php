<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReportType;
use App\Models\Property;
use App\Services\Reports\ReportPreview;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ReportPreviewCommand extends Command
{
    protected $signature = 'report:preview
        {property : Property slug}
        {type : weekly or monthly}
        {periodStart? : Period start, Y-m-d (a Monday for weekly, the 1st for monthly). Default: the latest complete period}
        {--no-charts : Do not render charts (tables are shown instead)}
        {--out= : Write the HTML to this file instead of the default}
        {--fresh : Ignore the stored report and the cached preview payload and build a new one (calls Google)}';

    protected $description = 'Render the report email as an HTML file for browsing. Sends no mail and creates no report or delivery rows';

    public function handle(ReportPreview $preview): int
    {
        $type = ReportType::tryFrom((string) $this->argument('type'));
        if ($type === null) {
            $this->error('Type must be weekly or monthly.');

            return self::INVALID;
        }

        $property = Property::query()->where('slug', $this->argument('property'))->first();
        if ($property === null) {
            $this->error("No property with slug '{$this->argument('property')}'.");

            return self::FAILURE;
        }

        try {
            $result = $preview->render(
                $property,
                $type,
                $this->argument('periodStart') ?: null,
                withCharts: ! $this->option('no-charts'),
                fresh: (bool) $this->option('fresh'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (Throwable $e) {
            $this->error(class_basename($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $path = (string) ($this->option('out') ?: $preview->defaultPath($property, $type, $result['period']));
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $result['html']);

        $this->line('Subject: '.$result['subject']);
        $this->line('Payload: '.$result['source']);
        $this->line('File:    '.$path);

        return self::SUCCESS;
    }
}
