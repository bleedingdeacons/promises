<?php

declare(strict_types=1);

namespace Promises\Tools\Unity;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Mcp\ToolException;
use Promises\Support\Presenter;
use Promises\Tools\BaseTool;
use Unity\Members\Interfaces\MemberRepository;

/**
 * unity_get_member — one member, by id or by email address.
 *
 * Email lookup is here because it is the question a helpline volunteer
 * actually arrives with ("who is this?"), and Unity's repository already
 * answers it. It is not a search: the address must match exactly.
 */
class GetMemberTool extends BaseTool
{
    public function __construct(
        private MemberRepository $members,
        private Presenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'unity_get_member';
    }

    public function title(): string
    {
        return 'Get a member';
    }

    public function description(): string
    {
        return 'Fetch one Unity member by id, or by exact personal email address. Use this rather than unity_list_members whenever you already know which member you want.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The member id.',
                ],
                'email' => [
                    'type' => 'string',
                    'description' => 'The member\'s exact personal email address. Only useful when masking is off, since a masked address will not match.',
                ],
            ],
            // Neither is individually required, but one of them must be
            // present. JSON Schema can say that with anyOf/required, and
            // saying it here means a well-behaved client never sends the
            // empty call that call() would otherwise have to reject.
            'anyOf' => [
                ['required' => ['id']],
                ['required' => ['email']],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        $email = $this->optionalString($arguments, 'email');

        if ($email !== '') {
            $member = $this->members->findByEmail($email);

            if ($member === null) {
                throw new ToolException(sprintf('No member has the email address "%s".', $email));
            }

            return $this->presenter->member($member);
        }

        $id = $this->requiredId($arguments, 'id');
        $member = $this->members->findById($id);

        if ($member === null) {
            throw new ToolException(sprintf('No member with id %d.', $id));
        }

        return $this->presenter->member($member);
    }
}
