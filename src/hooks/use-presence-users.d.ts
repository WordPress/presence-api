export interface PresenceUser {
	id: number;
	displayName: string;
	avatarUrl: string;
}

export interface UsePresenceUsersOptions {
	includeSelf?: boolean;
	fields?: string;
}

export interface UsePresenceUsersResult {
	isPresent: boolean;
	isLoading: boolean;
	users: PresenceUser[];
	error: Error | null;
}

export default function usePresenceUsers(
	room: string | null | undefined,
	options?: UsePresenceUsersOptions
): UsePresenceUsersResult;
