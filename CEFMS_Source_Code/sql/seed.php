<?php
// Seed script: php sql/seed.php  (creates demo users and events; passwords are hashed with password_hash)
require __DIR__ . '/../includes/bootstrap.php';
$pdo = db();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['payment','reservation','schedule','event','user'] as $t) $pdo->exec("TRUNCATE TABLE $t");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$hash = fn($p) => password_hash($p, PASSWORD_DEFAULT);
$u = $pdo->prepare('INSERT INTO user (fullName,email,phone,passwordHash,role,status) VALUES (?,?,?,?,?,?)');
$users = [
  ['Encik Hashim Abu Bakar','manager@cefms.my','0123456001','Manager@123','Event Manager','Active'],
  ['Siti Nur Aminah','siti@organizer.my','0123456002','Organizer@123','Organizer','Active'],
  ['Ahmad Daniel','daniel@organizer.my','0123456003','Organizer@123','Organizer','Active'],
  ['Lim Wei Jian','lim@organizer.my','0123456004','Organizer@123','Organizer','Active'],
  ['Raju Kumar','raju@organizer.my','0123456005','Organizer@123','Organizer','Inactive'],
  ['Aisyah Rahman','aisyah@mail.com','0123456006','Aisyah@123','Event Participant','Active'],
  ['Farid Hakim','farid@mail.com','0123456007','Farid@123','Event Participant','Active'],
];
foreach ($users as $r) { $u->execute([$r[0],$r[1],$r[2],$hash($r[3]),$r[4],$r[5]]); }

$e = $pdo->prepare('INSERT INTO event (organizerId,title,description,category,ticketPrice,totalSeats,availableSeats,status) VALUES (?,?,?,?,?,?,?,?)');
$s = $pdo->prepare('INSERT INTO schedule (eventId,venue,startDateTime,endDateTime,status) VALUES (?,?,?,?,?)');
$events = [
 [2,'Pesta Kebudayaan Johor','A three-day celebration of Johor heritage featuring Zapin dance, Kuda Kepang performances, traditional games and local street food from across the state.','Multi-cultural',35,300,300,'Published','Dataran Bandaraya Johor Bahru','2026-11-14 10:00','2026-11-14 22:00'],
 [2,'Festival Tarian Tradisi','Dance troupes from Malay, Chinese, Indian and Orang Asli communities perform Tarian Inang, Lion Dance, Bharatanatyam and Sewang in one evening.','Dance',25,200,200,'Published','Panggung Sari, Istana Budaya, Kuala Lumpur','2026-11-21 20:00','2026-11-21 23:00'],
 [3,'Pasar Seni Warisan','Hands-on craft market with songket weaving, batik painting and wood carving workshops led by master artisans.','Craft',15,150,150,'Published','Central Market Annexe, Kuala Lumpur','2026-11-08 09:00','2026-11-08 18:00'],
 [3,'Malam Muzik Rakyat','An evening of folk music: Dikir Barat, Gamelan, Sape and Kompang with live ensembles.','Music',40,250,250,'Published','Dewan Merdeka, Melaka','2026-12-05 19:30','2026-12-05 22:30'],
 [4,'Festival Makanan Warisan','Taste heritage dishes from every state - Laksa Johor, Nasi Kerabu, Assam Pedas and Kuih Tradisi.','Food',20,400,400,'Published','Jonker Street Night Market Square, Melaka','2026-11-28 17:00','2026-11-28 23:00'],
 [4,'Perarakan Thaipusam Heritage Night','Cultural showcase and procession exhibition marking Thaipusam traditions at Batu Caves.','Religious',10,500,500,'Published','Batu Caves Cultural Hall, Selangor','2026-12-12 18:00','2026-12-12 21:00'],
 [2,'Pameran Wayang Kulit','Exhibition and live performance of Wayang Kulit Kelantan by Tok Dalang masters.','Multi-cultural',18,120,120,'Pending','Muzium Negara, Kuala Lumpur','2026-12-19 14:00','2026-12-19 17:00'],
 [4,'Pesta Menuai Kaamatan','Harvest festival with Sumazau dance, traditional Kadazan-Dusun food and handicraft stalls.','Multi-cultural',22,300,296,'Completed','Dewan Kompleks Sukan, Kota Kinabalu','2026-10-17 10:00','2026-10-17 20:00'],
 [3,'Karnival Kompang Remaja','Youth Kompang competition and showcase.','Music',12,180,180,'Cancelled','Dewan Seri Pinang, Pulau Pinang','2026-10-24 10:00','2026-10-24 16:00'],
];
foreach ($events as $ev) {
  $e->execute([$ev[0],$ev[1],$ev[2],$ev[3],$ev[4],$ev[5],$ev[6],$ev[7]]);
  $id = $pdo->lastInsertId();
  $s->execute([$id,$ev[8],$ev[9],$ev[10], $ev[7]==='Cancelled' ? 'Cancelled' : 'Scheduled']);
}
// One finished event with 4 paid tickets, so the report and the archive have something to show
$cid = $pdo->query("SELECT eventId FROM event WHERE title='Pesta Menuai Kaamatan'")->fetchColumn();
$pdo->prepare("INSERT INTO reservation (userId,eventId,quantity,totalAmount,reservedDate,expiryDate,status,ticketCode) VALUES (6,?,3,66.00,'2026-10-10 09:00:00','2026-10-10 09:15:00','Paid','CEF-DEMO01'),(7,?,1,22.00,'2026-10-11 09:00:00','2026-10-11 09:15:00','Paid','CEF-DEMO02')")->execute([$cid, $cid]);
echo "Seeded: " . count($users) . " users, " . count($events) . " events\n";
