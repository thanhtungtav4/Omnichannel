<?php

namespace Tests\Feature\Modules;

use App\Modules\Crm\Models\Contact;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTagVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::create([
            'slug' => 'tag-vocab',
            'name' => 'Tag Vocab Test',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_update_tags_merges_new_tags_into_workspace_vocabulary(): void
    {
        $contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'Anh Nguyen',
            'tags' => ['VIP'],
        ]);

        $response = $this->actingAsOwner()->put(
            "/api/admin/contacts/{$contact->id}/tags",
            ['tags' => ['VIP', 'Hà Nội', 'Invisalign']],
        );

        $response->assertRedirect();
        $contact->refresh();
        $this->assertSame(['VIP', 'Hà Nội', 'Invisalign'], $contact->tags);

        $vocab = app(WorkspaceSettings::class)->get(
            $this->workspace,
            'tags.vocabulary',
            [],
        );
        sort($vocab);
        $this->assertSame(['Hà Nội', 'Invisalign', 'VIP'], $vocab);
    }

    public function test_update_tags_does_not_shrink_vocabulary_on_removal(): void
    {
        // Existing vocabulary pre-populated by an earlier session.
        app(WorkspaceSettings::class)->set(
            $this->workspace,
            'tags.vocabulary',
            ['VIP', 'OldTag'],
        );

        $contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'Anh Nguyen',
            'tags' => ['VIP', 'OldTag'],
        ]);

        // Remove OldTag from the contact.
        $this->actingAsOwner()->put(
            "/api/admin/contacts/{$contact->id}/tags",
            ['tags' => ['VIP']],
        )->assertRedirect();

        // Vocabulary should still contain OldTag (admins curate it).
        $vocab = app(WorkspaceSettings::class)->get(
            $this->workspace,
            'tags.vocabulary',
            [],
        );
        sort($vocab);
        $this->assertSame(['OldTag', 'VIP'], $vocab);
    }

    public function test_vocabulary_endpoint_returns_workspace_vocab(): void
    {
        app(WorkspaceSettings::class)->set(
            $this->workspace,
            'tags.vocabulary',
            ['VIP', 'Hà Nội'],
        );

        $response = $this->actingAsOwner()->get(
            "/api/admin/workspaces/{$this->workspace->id}/tag-vocabulary",
        );

        $response->assertOk();
        $response->assertJson(['vocabulary' => ['VIP', 'Hà Nội']]);
    }

    public function test_vocabulary_endpoint_rejects_other_workspace(): void
    {
        $other = Workspace::create([
            'slug' => 'other-ws',
            'name' => 'Other WS',
            'status' => 'ACTIVE',
        ]);
        $response = $this->actingAsOwner()->get(
            "/api/admin/workspaces/{$other->id}/tag-vocabulary",
        );
        $response->assertForbidden();
    }

    public function test_update_tags_dedups_case_insensitive(): void
    {
        $contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'Test',
            'tags' => ['VIP'],
        ]);

        $this->actingAsOwner()->put(
            "/api/admin/contacts/{$contact->id}/tags",
            ['tags' => ['VIP', 'vip', 'Vip']],
        )->assertRedirect();

        $contact->refresh();
        $this->assertCount(1, $contact->tags);
    }

    public function test_update_tags_rejects_too_many_tags(): void
    {
        $contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'Test',
            'tags' => [],
        ]);

        $tooMany = array_map(fn ($i) => "tag-$i", range(1, 25));

        // API routes return JSON 422 on validation errors, not session errors.
        $this->actingAsOwner()
            ->putJson("/api/admin/contacts/{$contact->id}/tags", ['tags' => $tooMany])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tags');
    }

    private function actingAsOwner()
    {
        $user = \App\Models\User::create([
            'name' => 'Owner',
            'email' => 'owner@tag-vocab.test',
            'password' => bcrypt('secret'),
            'role' => 'owner',
            'workspace_id' => $this->workspace->id,
        ]);

        return $this->actingAs($user);
    }
}