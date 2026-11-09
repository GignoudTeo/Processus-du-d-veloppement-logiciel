<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Création d'une séance par un administrateur : salle, créneau libre, capacité calculée (US1)
class CourseSlotsTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $day;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();
        $this->db->exec("INSERT INTO categories (id_categorie, nom_categorie) VALUES (1, 'Cardio')");
        $this->db->exec("INSERT INTO sports (id_sport, id_categorie, nom_sport) VALUES (1, 1, 'HIIT')");
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role) VALUES (101, 'marc@example.com', 'x', 'coach')");
        $this->db->exec("INSERT INTO coachs (id_coach, id_compte, nom, prenom) VALUES (1, 101, 'Martin', 'Marc')");
        $this->db->exec("INSERT INTO salles (id_salle, nom_salle, capacite) VALUES (1, 'Studio 1', 25), (2, 'Petit studio', 3)");

        $this->day = new DateTimeImmutable('2026-11-12', new DateTimeZone('Europe/Paris'));
        $this->now = new DateTimeImmutable('2026-11-09 12:00:00+00');
    }

    // Séance de 10 h à 11 h (heure de Paris) dans la salle 1
    private function bookRoom(int $roomId, string $start, string $end): void
    {
        $this->db->prepare(
            "INSERT INTO cours (titre, id_sport, id_coach, id_salle, date_debut, date_fin, nb_places_max) VALUES ('Prise', 1, 1, ?, ?, ?, 5)"
        )->execute([$roomId, $start, $end]);
    }

    private function slots(int $roomId): array
    {
        $bookings = getRoomBookings($this->db, $this->day);

        return getDaySlots($this->day, $bookings[$roomId] ?? [], $this->now);
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-11-12', 'id_salle' => '1', 'creneau' => '18:00', 'id_coach' => '1',
            'id_sport' => '1', 'titre' => 'Séance privée', 'description' => '',
        ], $overrides);
    }

    private function validate(array $overrides = []): array
    {
        $rooms   = getRoomsAvailability($this->db, $this->day, $this->now);
        $coaches = [['id_coach' => 1]];

        return validateSessionForm($this->input($overrides), $this->day, $rooms, $coaches, [1]);
    }

    // ---------- Capacité ----------

    public static function capacites(): array
    {
        return [
            'salle de 25' => [25, 5],
            'salle de 27' => [27, 5],
            'salle de 10' => [10, 2],
            'salle de 4'  => [4, 1],
            'salle de 0'  => [0, 1],
        ];
    }

    #[DataProvider('capacites')]
    public function testCapaciteDeLaSeance(int $roomCapacity, int $expected): void
    {
        $this->assertSame($expected, sessionCapacity($roomCapacity));
    }

    // ---------- Créneaux ----------

    public function testCreneauxDeLaJournee(): void
    {
        $slots = $this->slots(1);

        $this->assertCount(13, $slots);
        $this->assertSame('08:00', $slots[0]['debut']->format('H:i'));
        $this->assertSame('21:00', end($slots)['fin']->format('H:i'));
        $this->assertTrue($slots[0]['libre']);
    }

    public function testCreneauPrisGrise(): void
    {
        // 10:00-11:00 à Paris = 09:00-10:00 UTC
        $this->bookRoom(1, '2026-11-12 09:00:00+00', '2026-11-12 10:00:00+00');

        $slots = array_column($this->slots(1), null, null);
        $byTime = [];
        foreach ($slots as $slot) {
            $byTime[$slot['debut']->format('H:i')] = $slot;
        }

        $this->assertFalse($byTime['10:00']['libre']);
        $this->assertSame('occupe', $byTime['10:00']['raison']);
        // Les créneaux qui se suivent restent libres
        $this->assertTrue($byTime['09:00']['libre']);
        $this->assertTrue($byTime['11:00']['libre']);
        // L'autre salle n'est pas touchée
        $this->assertSame(13, count(array_filter($this->slots(2), fn ($s) => $s['libre'])));
    }

    public function testSeanceAChevalSurDeuxCreneaux(): void
    {
        // 10:30-11:30 à Paris
        $this->bookRoom(1, '2026-11-12 09:30:00+00', '2026-11-12 10:30:00+00');

        $taken = array_map(fn ($s) => $s['debut']->format('H:i'), array_filter($this->slots(1), fn ($s) => !$s['libre']));

        $this->assertSame(['10:00', '11:00'], array_values($taken));
    }

    public function testCreneauxPassesGrises(): void
    {
        $now   = new DateTimeImmutable('2026-11-12 09:30:00+00'); // 10:30 à Paris
        $slots = getDaySlots($this->day, [], $now);

        $this->assertSame('passe', $slots[2]['raison']); // 10:00
        $this->assertTrue($slots[3]['libre']);           // 11:00
    }

    public function testSalleSansCreneauLibreGrisee(): void
    {
        $this->bookRoom(2, '2026-11-12 07:00:00+00', '2026-11-12 20:00:00+00');

        $rooms = array_column(getRoomsAvailability($this->db, $this->day, $this->now), null, 'id_salle');

        $this->assertTrue($rooms[2]['complete']);
        $this->assertSame(0, $rooms[2]['creneaux_libres']);
        $this->assertFalse($rooms[1]['complete']);
        $this->assertSame(5, $rooms[1]['capacite_seance']);
        $this->assertSame(1, $rooms[2]['capacite_seance']);
    }

    // ---------- Formulaire et création ----------

    public function testFormulaireValideCalculeLaCapacite(): void
    {
        [$data, $errors] = $this->validate();

        $this->assertSame([], $errors);
        $this->assertSame(5, $data['nb_places_max']);
        $this->assertSame('18:00', $data['debut']->format('H:i'));
        $this->assertSame('19:00', $data['fin']->format('H:i'));
    }

    public function testCapaciteSaisieIgnoree(): void
    {
        [$data] = $this->validate(['nb_places_max' => '50']);

        $this->assertSame(5, $data['nb_places_max']);
    }

    // ---------- Séance classique : même parcours (salle, créneau libre), nombre de places choisi ----------

    private function validateClassic(array $overrides = []): array
    {
        $rooms = getRoomsAvailability($this->db, $this->day, $this->now);

        return validateSessionForm($this->input($overrides), $this->day, $rooms, [['id_coach' => 1]], [1], false);
    }

    public function testSeanceClassiqueGardeLeNombreDePlacesChoisi(): void
    {
        [$data, $errors] = $this->validateClassic(['nb_places_max' => '20']);

        $this->assertSame([], $errors);
        $this->assertSame(20, $data['nb_places_max']);
    }

    public function testSeanceClassiqueLimiteeALaCapaciteDeLaSalle(): void
    {
        $this->assertArrayHasKey('nb_places_max', $this->validateClassic(['nb_places_max' => '26'])[1]);
        $this->assertSame([], $this->validateClassic(['nb_places_max' => '25'])[1]);
    }

    public function testSeanceClassiqueNombreDePlacesObligatoire(): void
    {
        $this->assertArrayHasKey('nb_places_max', $this->validateClassic()[1]);
        $this->assertArrayHasKey('nb_places_max', $this->validateClassic(['nb_places_max' => '0'])[1]);
    }

    public function testSeanceClassiqueSurUnCreneauPrisRefusee(): void
    {
        $this->bookRoom(1, '2026-11-12 17:00:00+00', '2026-11-12 18:00:00+00'); // 18:00 à Paris

        $this->assertArrayHasKey('creneau', $this->validateClassic(['nb_places_max' => '20'])[1]);
    }

    public function testCreneauPrisRefuse(): void
    {
        $this->bookRoom(1, '2026-11-12 17:00:00+00', '2026-11-12 18:00:00+00'); // 18:00 à Paris

        $this->assertArrayHasKey('creneau', $this->validate()[1]);
    }

    public function testChampsObligatoires(): void
    {
        $errors = $this->validate(['id_salle' => '', 'id_coach' => '9', 'id_sport' => '', 'titre' => ' '])[1];

        $this->assertEqualsCanonicalizing(['id_salle', 'id_coach', 'id_sport', 'titre'], array_keys($errors));
        $this->assertArrayHasKey('creneau', $this->validate(['creneau' => '07:00'])[1]);
        $this->assertArrayHasKey('date', validateSessionForm($this->input(), null, [], [['id_coach' => 1]], [1])[1]);
    }

    public function testCreationApparaitDansLePlanning(): void
    {
        [$data] = $this->validate();

        $id = createSession($this->db, $data);

        $course = array_column(getCourses($this->db), null, 'id_cours')[$id];
        $this->assertSame('Séance privée', $course['titre']);
        $this->assertSame(5, (int) $course['nb_places_max']);
        $this->assertSame('Marc', $course['coach_prenom']);
    }

    public function testCreneauPrisEntreTempsRefuseALEnregistrement(): void
    {
        [$data] = $this->validate();
        $this->bookRoom(1, '2026-11-12 17:00:00+00', '2026-11-12 18:00:00+00');

        $this->assertNull(createSession($this->db, $data));
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM cours')->fetchColumn());
    }

    public function testDateDuFormulaire(): void
    {
        $this->assertSame('2026-11-12', parseSlotDate('2026-11-12')->format('Y-m-d'));
        $this->assertNull(parseSlotDate('2026-02-30'));
        $this->assertNull(parseSlotDate(null));
    }
}
