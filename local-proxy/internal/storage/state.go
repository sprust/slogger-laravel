package storage

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

const (
	StateVersion  = 1
	StateFileName = "local-proxy-state.json"
)

type State struct {
	Version   int    `json:"version"`
	File      string `json:"file"`
	Offset    int64  `json:"offset"`
	Completed bool   `json:"completed"`
}

type StateStore struct {
	dir string
}

func NewStateStore(dir string) *StateStore {
	return &StateStore{dir: dir}
}

func (s *StateStore) Load() (State, error) {
	data, err := os.ReadFile(filepath.Join(s.dir, StateFileName))
	if os.IsNotExist(err) {
		return State{Version: StateVersion}, nil
	}

	if err != nil {
		return State{}, fmt.Errorf("read sender state: %w", err)
	}

	state := State{}

	if err := json.Unmarshal(data, &state); err != nil {
		return State{}, fmt.Errorf("decode sender state: %w", err)
	}

	if state.Version != StateVersion {
		return State{}, fmt.Errorf("unsupported sender state version %d", state.Version)
	}

	if state.Offset < 0 {
		return State{}, fmt.Errorf("sender state offset must not be negative")
	}

	if state.File != "" && !ValidTraceFileName(state.File) {
		return State{}, fmt.Errorf("invalid trace file name in sender state")
	}

	if state.Completed && state.File == "" {
		return State{}, fmt.Errorf("completed sender state requires a file")
	}

	return state, nil
}

func (s *StateStore) Save(state State) error {
	state.Version = StateVersion

	if state.Offset < 0 || (state.File != "" && !ValidTraceFileName(state.File)) || (state.Completed && state.File == "") {
		return fmt.Errorf("invalid sender state")
	}

	data, err := json.Marshal(state)
	if err != nil {
		return err
	}

	data = append(data, '\n')

	temporary, err := os.CreateTemp(s.dir, ".local-proxy-state-*")
	if err != nil {
		return fmt.Errorf("create temporary sender state: %w", err)
	}

	temporaryPath := temporary.Name()
	defer os.Remove(temporaryPath)

	if err := temporary.Chmod(0o600); err != nil {
		temporary.Close()
		return err
	}

	if _, err := temporary.Write(data); err != nil {
		temporary.Close()
		return err
	}

	if err := temporary.Sync(); err != nil {
		temporary.Close()
		return err
	}

	if err := temporary.Close(); err != nil {
		return err
	}

	if err := os.Rename(temporaryPath, filepath.Join(s.dir, StateFileName)); err != nil {
		return fmt.Errorf("replace sender state: %w", err)
	}

	directory, err := os.Open(s.dir)
	if err != nil {
		return err
	}

	defer directory.Close()

	return directory.Sync()
}
