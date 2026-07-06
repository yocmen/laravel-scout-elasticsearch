<?php

return [
    'start' => 'Importing [:searchable]',
    'done' => 'All [:searchable] records have been imported.',
    'done.queue' => 'Import job dispatched to the queue.',
    'summary' => 'Reindex summary for [:searchable]',
    'summary.previous' => 'Documents in previous index',
    'summary.expected' => 'Eligible records in database',
    'summary.indexed' => 'Documents in new index',
    'summary.difference' => 'Difference vs previous index',
    'summary.duration' => 'Duration',
    'summary.none' => 'none (new index)',
    'mismatch' => 'Indexed :indexed of :expected eligible records (:missing fewer). This can be expected when shouldBeSearchable() filters records out; otherwise a chunk may have failed to index — re-run the import to confirm.',
];
