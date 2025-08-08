<?php

declare(strict_types=1);

/**
 * Requires:
 *
 * composer require --dev phpdocumentor/graphviz
 * composer require --dev phpdocumentor/reflection
 */

require __DIR__ . '/../vendor/autoload.php';

use phpDocumentor\Reflection\Php\ProjectFactory;
use phpDocumentor\GraphViz\Graph;
use phpDocumentor\GraphViz\Node;
use phpDocumentor\GraphViz\Edge;
use phpDocumentor\Reflection\File\LocalFile;

// Discover PHP source files and build nodes/edges
$projectFactory = ProjectFactory::createInstance();

$sourceDir = __DIR__ . '/../src';
$files     = [];
$iterator  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir));
foreach ($iterator as $splFile) {
    if ($splFile->isDir()) {
        continue;
    }
    if ($splFile->getExtension() !== 'php') {
        continue;
    }
    $files[] = new LocalFile($splFile->getPathname());
}

/** @var \phpDocumentor\Reflection\Php\Project $project */
$project = $projectFactory->create('MyProject', $files);

$graph = Graph::create('classDiagram');

foreach ($project->getFiles() as $file) {
    foreach ($file->getClasses() as $class) {
        $className = (string) $class->getFqsen();

        // Ensure node for current class
        if ($graph->findNode($className) === null) {
            $node = Node::create($className);
            $node->setLabel($class->getName());
            $graph->setNode($node);
        }

        // Add edge to parent class if it exists
        $parent = $class->getParent();
        if ($parent !== null) {
            $parentName = (string) $parent;
            if ($graph->findNode($parentName) === null) {
                $graph->setNode(Node::create($parentName));
            }
            $graph->link(Edge::create($graph->findNode($parentName), $graph->findNode($className)));
        }
    }
}

$dir = __DIR__ . '/../.phpdoc/api/graphs';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$filenameStem = 'generate-graphs';

file_put_contents("$dir/$filenameStem.dot", (string)$graph);
// Verify file exists
if (!file_exists("$dir/$filenameStem.dot")) {
    fwrite(STDERR, "Failed to create .dot file\n");
    exit(1);
} else {
    echo "Dot file created at: $dir/$filenameStem.dot\n";
}

// Convert .dot to .svg using Graphviz
$dotFile = realpath("$dir/$filenameStem.dot");
$svgFile = realpath($dir) . DIRECTORY_SEPARATOR . "$filenameStem.svg";
$cmd     = 'dot -Tsvg ' . escapeshellarg($dotFile) . ' -o ' . escapeshellarg($svgFile);
exec($cmd, $output, $code);
if ($code !== 0) {
    fwrite(STDERR, 'Graphviz conversion failed: ' . implode("\n", $output) . "\n");
} else {
    echo "SVG generated at: $svgFile\n";
}
