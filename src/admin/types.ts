export interface Progress {
	done: number;
	total: number;
}

export interface Settings {
	provider: string;
	model: string;
	dimensions: number | null;
}

export interface AvailableModel {
	provider: string;
	model: string;
	label: string;
	dimensions: number | null;
}

export interface StatusResponse {
	is_supported: boolean;
	installed: boolean;
	indexed: number;
	table_dims: number | null;
	progress: Progress | null;
	settings: Settings;
	available_models: AvailableModel[];
	dim_changed: boolean;
}

export interface SettingsApiResponse {
	wp_mariadb_vector_search_settings: Settings;
}

export interface ReindexResponse {
	rebuilt: boolean;
}
