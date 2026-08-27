<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            // Both nullable json arrays, replacing the single pdf_path
            // string — a Proposal can now carry any number of attached
            // files, of any type (not just PDF). attachment_paths holds
            // the real stored path on the 'local' disk for each file (see
            // the old pdf_path column's own comment for why 'local', not
            // 'avatars'); attachment_names holds the ORIGINAL client
            // filename for each one, keyed by its stored path — Filament's
            // FileUpload field stores files under a generated name to
            // avoid collisions, so the original name has to be tracked
            // separately to show/download anything more meaningful than a
            // random ID.
            $table->json('attachment_paths')->nullable()->after('pdf_path');
            $table->json('attachment_names')->nullable()->after('attachment_paths');
        });

        // Backfill: every existing Proposal's single pdf_path becomes a
        // one-file attachment_paths array. The original client filename
        // was never captured historically (pdf_path only ever stored the
        // generated on-disk name), so there is no real name to backfill —
        // falls back to the exact same "{Company} - {Proposal ID}.pdf"
        // name ProposalResource::pdfDownloadFilename() already produces
        // today, so a pre-existing attachment downloads with an identical
        // name before and after this migration. Duplicated here (not
        // calling into app code) since a migration must keep working
        // exactly as written even if that app code later changes.
        DB::table('proposals')
            ->join('prospects', 'prospects.id', '=', 'proposals.prospect_id')
            ->whereNotNull('proposals.pdf_path')
            ->select('proposals.id', 'proposals.pdf_path', 'prospects.company_name')
            ->orderBy('proposals.id')
            ->get()
            ->each(function (object $row) {
                $companyName = trim(preg_replace(
                    '/\s+/',
                    ' ',
                    preg_replace('/[\/\\\\:*?"<>|]+/', ' ', $row->company_name),
                ));

                DB::table('proposals')
                    ->where('id', $row->id)
                    ->update([
                        'attachment_paths' => json_encode([$row->pdf_path]),
                        // Keyed by stored path, not a plain indexed list —
                        // matches the exact shape Filament's own FileUpload
                        // field writes via storeFileNamesIn() (see
                        // BaseFileUpload::storeFileName()), which is what
                        // Proposal::attachments() reads this column as.
                        'attachment_names' => json_encode([$row->pdf_path => "{$companyName} - {$row->id}.pdf"]),
                    ]);
            });

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('pdf_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('pdf_path')->nullable()->after('notes');
        });

        // Only the first attachment survives a rollback — pdf_path was
        // always a single value, so this is a lossy but honest inverse
        // (matches how the original up() migration's own down() already
        // behaved: a plain dropColumn(), no attempt to preserve data
        // beyond what the reverted schema can actually hold).
        DB::table('proposals')
            ->whereNotNull('attachment_paths')
            ->select('id', 'attachment_paths')
            ->orderBy('id')
            ->get()
            ->each(function (object $row) {
                $paths = json_decode($row->attachment_paths, true) ?? [];

                if ($paths === []) {
                    return;
                }

                DB::table('proposals')->where('id', $row->id)->update(['pdf_path' => $paths[0]]);
            });

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['attachment_paths', 'attachment_names']);
        });
    }
};
