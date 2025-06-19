<?php
interface ICommand
{
    public function execute(array $args, Auth $auth, &$interactionState): array;
    public function getArgumentDefinition(): array;
    public function getDescription(): string;
    public function getUsage(): string;
}
