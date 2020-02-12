<?php


namespace Prometheus;
/**
 * Class DirectFileStore
 * @package Prometheus
 * Provides an interface to backing Prometheus metrics in a serialized for via state and update files.
 */
class DirectFileStore
{
    private $stateFile;
    private $metricsState = [];

    /**
     * DirectFileStore constructor.
     * @param string $stateFile the file backing the state
     */
    public function __construct(string $stateFile)
    {
        if (!is_file($stateFile)) {
            file_put_contents($stateFile, '{}');
        }
        $this->stateFile = $stateFile;
        $this->metricsState = $this->getMetricsFromFile($stateFile);
    }

    /**
     * @param $metrics array MetricFamilySamples
     * Serializes and writes MetricFamilySamples[] to $this->stateFile
     */
    public function save(array $metrics): void
    {
        $handle = fopen($this->stateFile, 'w');
        fwrite($handle, json_encode($metrics));
        fclose($handle);
    }

    /**
     * @param $path string path to directory
     * @param bool $consume bool delete files after read
     * @return array MetricFamilySamples
     * Reads metrics from all files in $path, combines them with $this->stateFile, and returns the updated state
     */
    public function fromDirectory(string $path, bool $consume=true)
    {
        $collection = array_diff(scandir($path), ['..', '.']);
        foreach ($collection as $file) {
            $this->fromFile($path.'/'.$file, $consume);
        }

        return $this->metricsState;
    }

    /**
     * @param $file string path to file
     * @param bool $consume bool delete files after read
     * @return array MetricFamilySamples
     * Reads metrics from file at $file, combines them with $this->stateFile, and returns the updated state
     */
    public function fromFile(string $file, $consume=true): array
    {
        $this->combineWithState($this->getMetricsFromFile($file));
        if ($consume) {
            unlink($file);
        }
        return $this->metricsState;
    }

    /**
     * @param $newMetrics array MetricsFamilySamples
     * Inspects each metric in $newMetrics and updates a cooresponding entry in $this->metricsState if applicable
     */
    private function combineWithState(array $newMetrics): void
    {
        $metrics = [];
        foreach ($newMetrics as $metric) {
            $stateMetric = $this->getMetricFromState($metric);
            if ($stateMetric !== null) {
                $metrics[] = $this->combineMetrics($stateMetric, $metric);
            } else {
                $metrics[] = $metric;
            }
        }
        $this->metricsState = $metrics;
    }

    /**
     * @param $file string the file path
     * @return array MetricsFamilySamples
     * reads $file and deserializes the contents to MetricFamilySamples
     */
    private function getMetricsFromFile(string $file): array
    {
        $handle = fopen($file, 'r');
        $raw = '[]';
        if ($handle) {
            $raw = fread($handle, filesize($file));
            fclose($handle);
        }
        $parsed = json_decode($raw, $assoc=true);
        $metrics = [];
        foreach ($parsed as $data) {
            $metrics[] = new MetricFamilySamples($data);
        }
        return $metrics;
    }

    /**
     * @param MetricFamilySamples $metric
     * @return MetricFamilySamples|null
     * Returns metric from $this->metricsState that matches $metric based on name and labelNames.  Returns null if not found.
     */
    private function getMetricFromState(MetricFamilySamples $metric): ?MetricFamilySamples
    {
        foreach($this->metricsState as $stateMetric) {
            if (
                $metric->getName() === $stateMetric->getName()
                and count(array_diff($metric->getLabelNames(), $stateMetric->getLabelNames())) === 0
            ) {
                return $stateMetric;
            }
        }
        return null;
    }

    /**
     * @param MetricFamilySamples $left the old metric
     * @param MetricFamilySamples $right the new metric
     * @return MetricFamilySamples an instance of the updated metric
     * Adds the values of the samples of $left and $right
     */
    private function combineMetrics(MetricFamilySamples $left, MetricFamilySamples $right): MetricFamilySamples
    {
        $newSamples = [];
        foreach($left->getSamples() as $leftSample) {
            $newSampleDefinition = null;
            foreach ($right->getSamples() as $rightSample) {
                if (count(array_diff($rightSample->getLabelValues(), $leftSample->getLabelValues())) === 0) {
                    $newSampleDefinition = [
                        'name'        => $leftSample->getName(),
                        'labelNames'  => $leftSample->getLabelNames(),
                        'labelValues' => $leftSample->getLabelValues(),
                        'value'       => $leftSample->getValue() + $rightSample->getValue()
                    ];
                }
            }
            if ($newSampleDefinition !== null) {
                array_push($newSamples, $newSampleDefinition);
            } else {
                array_push($newSamples, $leftSample);
            }
        }
        return new MetricFamilySamples([
            'name' => $right->getName(),
            'type' => $right->getType(),
            'help' => $right->getHelp(),
            'labelNames' => $right->getLabelNames(),
            'samples' => $newSamples
        ]);
    }
}