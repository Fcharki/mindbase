<?php

namespace Database\Seeders;

use App\Actions\KnowledgeEntry\ScheduleEntrySummary;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class DemoKnowledgeEntriesSeeder extends Seeder
{
    /**
     * Seed eight knowledge entries into the current user's workspace
     * so the summarization feature can be exercised on a larger dataset.
     */
    public function run(): void
    {
        $workspace = Workspace::where('name', 'Personal knowledge')->first()
            ?? Workspace::first();

        if ($workspace === null) {
            $this->command->error('No workspace found. Create a workspace first before running this seeder.');

            return;
        }

        $author = User::where('email', 'kaizen@example.com')->first()
            ?? $workspace->owner
            ?? User::first();

        $entries = [
            [
                'title' => 'PostgreSQL full-text search basics',
                'content' => '<p>PostgreSQL ships a built-in full-text search engine that is powerful enough for most applications. Unlike LIKE-based queries, it understands language: it tokenizes text, discards stop words, and stems the remaining terms so that "running" matches "run".</p><p>The core pieces are the tsvector and tsquery data types. A tsvector stores the searchable form of a document, while a tsquery represents the query. The match operator @@ returns true when a tsquery matches a tsvector.</p><p>To get good performance you create a GIN index on the tsvector. Because the index is what makes searches fast, you must keep it updated whenever rows change, which you can do with a trigger or by storing a generated column that computes the tsvector at write time.</p><p>Ranking is the final touch: the ts_rank function sorts results by relevance, weighting matches in the title higher than those in the body. This is how small teams ship decent search without a dedicated search engine like Elasticsearch.</p>',
            ],
            [
                'title' => 'Why containerizing the app matters',
                'content' => '<p>Containers bundle an application with everything it needs to run: language runtime, system libraries, and configuration. This removes the classic "it works on my machine" problem because the exact same artifact runs in a local laptop and in production.</p><p>Isolation is another benefit. Each service in this project runs in its own container with the ports and resources it needs, so the Postgres database, the web server, and the AI service do not fight over the same installed packages.</p><p>Images are built from a Dockerfile, which acts as a versioned recipe. When the recipe changes, a new image can be built and rolled out, and the previous one rolled back if something breaks.</p><p>Reproducibility is the real win: a developer can clone the repository and spin up the whole stack with a single docker compose up command, confident that the environment matches what everyone else on the team uses.</p>',
            ],
            [
                'title' => 'How the T5 summarization model works',
                'content' => '<p>T5, which stands for Text-to-Text Transfer Transformer, treats every natural language problem as a text-to-text task. Even a summarization task is framed as taking a long document as input and producing a shorter text as output.</p><p>The model used in this project is a fine-tuned variant of T5 called Falconsai/text_summarization. It was trained on summarization datasets so that prepending the keyword "summarize:" to the input tells the model which task to perform.</p><p>At generation time the model predicts one token at a time. The simplest decoding strategy, greedy decoding, always picks the most likely next token. More sophisticated strategies like beam search keep several candidate sequences and produce more consistent output at the cost of longer running time.</p><p>Because the model is small enough to run on a laptop CPU, it can be served directly from the application with no cloud API calls. That keeps the text inside the machine and makes the feature usable offline.</p>',
            ],
            [
                'title' => 'Laravel queues and retry strategies',
                'content' => '<p>Queues move slow work out of the request cycle. Instead of generating an email, a report, or a machine learning inference synchronously, the application pushes a job onto a queue and responds to the user immediately. A worker process then picks the job up and does the heavy lifting.</p><p>Laravel makes this easy with the Queue facade and the dispatch helper. Any class implementing the ShouldQueue interface is sent to the queue when dispatched.</p><p>Failures are expected, so jobs support retries. A failed job can be attempted several times with a backoff delay before being marked failed and stored for inspection. This is important for unreliable external services like SMTP servers or third-party APIs.</p><p>Monitoring is the final piece. Dashboard tools show only failed jobs at first, and knowing the queue depth, the time jobs spend waiting, and the failure rate is what separates a queue setup that works from one that silently swallows work.</p>',
            ],
            [
                'title' => 'GIN indexes versus btree for search',
                'content' => '<p>A btree index is the default in PostgreSQL and the right tool for equality and range queries: WHERE id = 5 or WHERE created_at > now() - interval. It stores values in sorted order, which makes lookups logarithmic.</p><p>A GIN, or generalized inverted index, is built for data that contains many keys per row: the array, JSONB, and tsvector types. It maps each key to the rows containing it, which is exactly what full-text search needs when a document contains hundreds of terms.</p><p>Choosing the wrong index type silently hurts: a query that could scan a GIN index in milliseconds may fall back to a full table scan. The explain analyze output shows whether an index is used and how expensive the plan is.</p><p>Indexes are not free storage or write overhead: every insert now has to update the index structure. The practical rule is to index for the queries the application actually runs, measured with data that looks like production.</p>',
            ],
            [
                'title' => 'CSRF protection for API requests',
                'content' => '<p>Cross-site request forgery happens when a malicious site tricks a logged-in user into performing an unwanted action, like deleting a record or changing a password, on a site where they have an active session.</p><p>The classic defense is a CSRF token: a secret that is generated per session, embedded in forms or custom headers, and verified on every state-changing request. A cross-origin attacker cannot read the token, so forged requests fail validation.</p><p>In modern SPAs the frontend reads a CSRF cookie set by the backend and sends the value in a custom header such as X-XSRF-TOKEN. Laravel validates this header automatically for domain requests listed as stateful, using the double submit cookie pattern.</p><p>Since browsers are increasingly strict about cookies, passing the token through a header is the approach this project uses, and keeping the frontend and backend under the same base domain keeps the cookie exchange simple.</p>',
            ],
            [
                'title' => 'Structuring API routes around workspaces',
                'content' => '<p>Nesting resources under their parent is a REST convention that models ownership. Rather than exposing a flat /entries collection, the API exposes /workspaces/{workspace}/entries, making it obvious that an entry belongs to a workspace.</p><p>This nesting also enforces authorization in the URL itself. The route model binding resolves both the workspace and the entry, and a small check that the entry belongs to the workspace prevents cross-workspace access.</p><p>For features built on top of a resource, the same nesting applies: the tagging endpoint lives at /workspaces/{workspace}/entries/{entry}/tags, and the summarization endpoint lives right next to it as a POST to .../summarize.</p><p>Each endpoint stays small because it only operates on the child it receives, and the frontend always has the workspace id available from navigation, so calling these endpoints is straightforward.</p>',
            ],
            [
                'title' => 'Testing AI features without an API key',
                'content' => '<p>Local machine learning models have an advantage over cloud APIs when testing: there is no external dependency to mock. The summarization model runs inside a container in the same compose stack as the backend, so tests can hit the real endpoint without network access.</p><p>The trade-off is speed. The first request loads the model from disk, which can take several seconds, so feature tests that summarize content must tolerate cold starts or the model must stay warm.</p><p>For unit tests that should not touch the model at all, the PHP service can be swapped for a fake that returns a canned summary. Using dependency injection, the controller depends on an interface or a small service class that is easy to replace in the test.</p><p>Finally, asserting on the error paths matters as much as the happy path: an unavailable service should return 503, empty input 422, and an empty summary 502, so the frontend can show the right message.</p>',
            ],
        ];

        foreach ($entries as $entry) {
            $knowledgeEntry = KnowledgeEntry::updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'title' => $entry['title'],
                ],
                [
                    'content' => $entry['content'],
                    'created_by' => $author->id,
                ]
            );

            // Written through the model, the seeder skips the controller path
            // that queues a summary and the chunk index. Without this the
            // entries exist but answer nothing: retrieval reads entry_chunks,
            // and there would be none.
            app(ScheduleEntrySummary::class, ['entry' => $knowledgeEntry])->handle();
        }

        $this->command->info(
            'Created '.count($entries).' knowledge entries in workspace "'.$workspace->name.'".'
        );
        $this->command->info(
            'Summary and chunk indexing jobs are queued for them; a running '
                .'queue worker is what turns those into searchable entries. Use '
                .'"php artisan entries:reindex" to recover entries seeded before this.'
        );
    }
}
